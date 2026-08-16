<?php

namespace App\Http\Controllers\Api;

use App\Domain\Security\Oidc\OidcClient;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OpenID Connect login (GitHub issue #16) — an additional way to
 * authenticate alongside the existing email/password flow
 * (AuthController), not a replacement for it: an admin who misconfigures
 * the OIDC provider settings doesn't lock themselves (or anyone else) out,
 * since the password form keeps working regardless.
 *
 * redirect()/callback() are genuine full-page browser navigations — the
 * browser leaves this app entirely to authenticate at the provider, then
 * comes back — not XHR calls the SPA's JS makes. That's why
 * routes/api.php registers these two explicitly with the 'web' middleware
 * group *and* excludes Sanctum's own Origin/Referer-based stateful
 * detection (bootstrap/app.php's ->statefulApi()) for them specifically,
 * rather than just relying on the latter: the callback leg's Referer is
 * the *provider's* domain, not ours, so Sanctum would never consider it a
 * "stateful" request and no session would start there at all if left to
 * its own conditional check alone — but the redirect leg's Referer *does*
 * match (a real navigation from this app's own SPA), which caused a
 * subtler bug when both mechanisms were left active together: Sanctum's
 * conditional prepend and the explicit 'web' group each ran their own
 * EncryptCookies+StartSession for that one request, and the second pass
 * failed to re-decrypt a cookie value the first pass had already decrypted
 * in place, silently starting a brand new (empty) session instead of
 * reading the real one — confirmed live, not theoretical. Excluding
 * Sanctum's conditional middleware for both routes makes the explicit
 * 'web' group the *only* session mechanism, applied exactly once,
 * regardless of which leg's Origin/Referer would or wouldn't otherwise
 * have matched.
 */
class OidcAuthController extends Controller
{
    /**
     * Pocket ID (and most other providers) has no single standard claim for
     * an application-specific role — a level needs a provider-side custom
     * claim an admin explicitly configures (Pocket ID: Admin UI -> a
     * group's or a user's "Custom Claims", a key/value pair added to that
     * identity's ID token). Both keys are `medinv_` prefixed so they read
     * unambiguously as "this claim is specifically for MedInv" in Pocket
     * ID's claim list, next to whatever other apps' custom claims might
     * also be configured there — matching this app's own convention of
     * prefixing everything it owns (CLAUDE.md: "All environment variables
     * are MEDINV_-prefixed").
     */
    private const LEVEL_CLAIM_KEY = 'medinv_level';

    /**
     * Optional override for the standard OIDC "name" claim (see
     * nameFromClaims()) — checked first, since a Pocket ID admin who
     * explicitly went to the trouble of configuring a MedInv-specific
     * custom claim clearly means for it to win. Unlike the level, a
     * perfectly good standard claim already exists for this and needs no
     * provider-side configuration at all, so this is only for the case
     * where an admin specifically wants MedInv to show a different name
     * than the identity's regular one.
     */
    private const NAME_CLAIM_KEY = 'medinv_name';

    public function __construct(private readonly OidcClient $oidc) {}

    /** Public — LoginPage.tsx calls this to decide whether to render the SSO button at all, and with what label. */
    public function config()
    {
        return response()->json([
            'enabled' => $this->oidc->isEnabled(),
            'provider_name' => $this->oidc->providerName(),
        ]);
    }

    public function redirect(Request $request): RedirectResponse
    {
        abort_unless($this->oidc->isEnabled(), 404);

        $auth = $this->oidc->buildAuthorizationRequest();
        $request->session()->put('oidc.state', $auth['state']);
        $request->session()->put('oidc.nonce', $auth['nonce']);
        $request->session()->put('oidc.code_verifier', $auth['code_verifier']);

        return redirect()->away($auth['url']);
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless($this->oidc->isEnabled(), 404);

        // Pulled (not just read), so a captured/replayed callback URL can
        // never be completed twice — the second attempt finds nothing to
        // match against and fails the state check below.
        $state = $request->session()->pull('oidc.state');
        $nonce = $request->session()->pull('oidc.nonce');
        $codeVerifier = $request->session()->pull('oidc.code_verifier');

        if ($request->query('error')) {
            // The provider itself declined, or the user cancelled at its consent
            // screen — not a bug on our end, but still worth a log entry.
            return $this->failure($request, 'oidc_provider_error', (string) $request->query('error_description', (string) $request->query('error')));
        }

        if (! $state || $state !== $request->query('state')) {
            return $this->failure($request, 'oidc_state_mismatch', 'OIDC callback state did not match the stored session state.');
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return $this->failure($request, 'oidc_failed', 'OIDC callback is missing an authorization code.');
        }

        try {
            $tokens = $this->oidc->exchangeCodeForTokens($code, (string) $codeVerifier);
            $claims = $this->oidc->verifyIdToken((string) ($tokens['id_token'] ?? ''), (string) $nonce);
        } catch (\Throwable $e) {
            return $this->failure($request, 'oidc_failed', 'OIDC token exchange/verification failed: '.$e->getMessage());
        }

        $user = $this->resolveUser($claims);
        if ($user === null) {
            return $this->failure($request, 'oidc_no_account', 'No matching account, and auto-provisioning is disabled or the email is unverified.', ['subject' => $claims->sub ?? null]);
        }

        if (! $user->is_active) {
            return $this->failure($request, 'oidc_account_deactivated', 'OIDC login attempt for a deactivated account.', ['user_id' => $user->id]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        Log::info('User logged in via OIDC', ['user_id' => $user->id, 'email' => $user->email, 'ip' => $request->ip()]);

        return redirect($this->frontendUrl('/'));
    }

    /**
     * Builds an absolute URL back to the SPA — NOT necessarily this app's
     * own base URL. In the single-port Docker deployment (CLAUDE.md: "Two
     * apps, one deployable image, one configurable port") the SPA and the
     * API are the same origin, so plain redirect($path) would already be
     * correct; but local dev commonly runs them on two separate ports
     * (backend on :8000, frontend's `npm run dev` on :5173), and a browser
     * finishing the OIDC round trip needs to land back on the *frontend's*
     * origin, not wherever this API happens to be served from. `FRONTEND_URL`
     * already exists for exactly this cross-origin-dev distinction
     * (config/cors.php's `allowed_origins`) — reusing it here, rather than
     * introducing a second setting for the same concept, and defaulting to
     * config('app.url') instead of cors.php's own hardcoded ":5173" default
     * (correct for CORS's convenience-default purpose, wrong for this one):
     * unset in Docker, so this falls through to the same-origin app URL,
     * which is exactly right there.
     */
    private function frontendUrl(string $path): string
    {
        return rtrim(env('FRONTEND_URL', config('app.url')), '/').$path;
    }

    /**
     * Matches an existing account, or auto-provisions a new one. By the
     * time this runs, OidcClient::verifyIdToken() has already confirmed
     * the ID token's signature, issuer, audience and nonce — what's left
     * here is purely account-linking policy, not token trust.
     *
     * Lookup order: `oidc_subject` first (the stable, provider-scoped
     * identifier — set on every account this method has ever resolved
     * before), then `email` for a first-time login against an account
     * that already existed (an admin-created account, or one from
     * password-based signup) — but only when the ID token's email claim
     * can actually be trusted (see the emailVerified handling below); an
     * unverified email must never be used to silently take over someone
     * else's existing account. A matched-by-email account is linked
     * (`oidc_subject` saved) so every later login skips the email lookup
     * entirely and is immune to that account's email later changing at
     * the provider.
     *
     * Returns null when no account exists and either auto-provisioning is
     * disabled (`oidc.auto_provision`, admin-configurable, default false —
     * requiring an admin to pre-create the account) or the email can't be
     * trusted to create one from.
     */
    private function resolveUser(\stdClass $claims): ?User
    {
        $subject = (string) ($claims->sub ?? '');
        $email = $claims->email ?? null;

        // Trust the email unless the provider explicitly disclaims it. A
        // claim value can arrive as a real bool or (some providers) the
        // string "false"/"true" — filter_var normalizes either. Absence of
        // the claim entirely (some minimal providers never emit it) is
        // treated as trusted: the admin who configured this specific
        // issuer has already vouched for the provider being trustworthy.
        $emailVerifiedClaim = $claims->email_verified ?? null;
        $emailVerified = $emailVerifiedClaim === null ? true : filter_var($emailVerifiedClaim, FILTER_VALIDATE_BOOLEAN);

        $user = User::query()->where('oidc_subject', $subject)->first();
        $matchedBy = $user ? 'oidc_subject' : null;

        if (! $user && $email && $emailVerified) {
            $user = User::query()->where('email', $email)->first();
            if ($user) {
                $matchedBy = 'email';
                if (! $user->oidc_subject) {
                    $user->oidc_subject = $subject;
                    $user->save();
                }
            }
        }

        if ($user) {
            $this->syncFromClaims($user, $claims);

            // The INFO-level "User logged in via OIDC" line in callback() is
            // deliberately the only OIDC line at that level — enough for an
            // ordinary audit trail ("who logged in, when"). Everything that
            // explains *how* that resolution happened (which lookup matched,
            // what the medinv_name/medinv_level custom claims actually
            // contained) is only useful while diagnosing a misconfigured
            // provider/claim mapping, so it belongs at DEBUG instead of
            // adding noise to every ordinary login.
            Log::debug('OIDC login: resolved to an existing account', [
                'user_id' => $user->id,
                'subject' => $subject,
                'issuer' => $claims->iss ?? null,
                'matched_by' => $matchedBy,
                'name_claim' => $this->nameFromClaims($claims),
                'level_claim' => $this->levelFromClaims($claims),
            ]);

            return $user;
        }

        if (! SystemSetting::get('oidc.auto_provision', false) || ! $email || ! $emailVerified) {
            Log::debug('OIDC login: no matching account and not auto-provisioning', [
                'subject' => $subject,
                'issuer' => $claims->iss ?? null,
                'auto_provision' => SystemSetting::get('oidc.auto_provision', false),
                'email' => $email,
                'email_verified' => $emailVerified,
            ]);

            return null;
        }

        $user = User::query()->create([
            'name' => $this->nameFromClaims($claims) ?? explode('@', $email)[0],
            'email' => $email,
            // Unguessable and never used — an OIDC-provisioned account has
            // no local password to log in with; only the OIDC flow (or an
            // admin-initiated password reset afterwards) can authenticate it.
            'password' => Hash::make(Str::random(40)),
            // An explicit medinv_level claim (see LEVEL_CLAIM_KEY's docblock)
            // is trusted outright, admin level included — an admin who
            // configured that specific claim for this specific identity has
            // already made that call themselves. Absent that, the
            // *system-wide* default (oidc.default_level) is still clamped to
            // guest/user, same as before: with no explicit per-identity
            // signal at all, silently defaulting a brand new account to
            // admin remains too risky to do implicitly.
            'level' => $this->levelFromClaims($claims) ?? $this->clampedDefaultLevel(),
            'is_active' => true,
            'oidc_subject' => $subject,
        ]);

        Log::debug('OIDC login: auto-provisioned a new account', [
            'user_id' => $user->id,
            'subject' => $subject,
            'issuer' => $claims->iss ?? null,
            'level' => $user->level,
            'name_claim' => $this->nameFromClaims($claims),
            'level_claim' => $this->levelFromClaims($claims),
        ]);

        return $user;
    }

    /**
     * Keeps an already-resolved (existing) account's name/level in sync
     * with the provider on every login — not just at the moment an account
     * is first created — so Pocket ID (or whatever's configured) genuinely
     * stays the source of truth for both, the way GitHub issue #16's whole
     * premise ("use an already-existing central identity management
     * instead of a separate one") intends. Only touches a field the
     * provider actually sent a usable value for; either one being absent
     * (or, for level, present but not one of guest/user/admin) leaves that
     * field exactly as it already was — this never *removes* a level an
     * admin set locally, it only overrides it when the provider explicitly
     * says to.
     */
    private function syncFromClaims(User $user, \stdClass $claims): void
    {
        $dirty = false;

        $name = $this->nameFromClaims($claims);
        if ($name !== null && $user->name !== $name) {
            $user->name = $name;
            $dirty = true;
        }

        $level = $this->levelFromClaims($claims);
        if ($level !== null && $user->level !== $level) {
            Log::info('OIDC login changed a user\'s level via medinv_level claim', [
                'user_id' => $user->id, 'from' => $user->level, 'to' => $level,
            ]);
            $user->level = $level;
            $dirty = true;
        }

        if ($dirty) {
            $user->save();
        }
    }

    /**
     * The medinv_name custom claim (see NAME_CLAIM_KEY's docblock) wins if
     * present, otherwise falls back to the standard "name" claim — either
     * one being an empty/non-string value is treated the same as it being
     * absent entirely, not as "the name should be blanked out".
     */
    private function nameFromClaims(\stdClass $claims): ?string
    {
        $name = $claims->{self::NAME_CLAIM_KEY} ?? $claims->name ?? null;

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    /**
     * Reads the medinv_level custom claim (see LEVEL_CLAIM_KEY's docblock)
     * — trimmed/lowercased so a Pocket ID admin typing "Admin" or "ADMIN "
     * into the custom-claims UI still matches. Returns null (not a level)
     * for anything that isn't exactly guest/user/admin, including the
     * claim being entirely absent — the caller treats null as "no opinion,
     * leave whatever level policy already applies untouched" throughout.
     */
    private function levelFromClaims(\stdClass $claims): ?string
    {
        $value = $claims->{self::LEVEL_CLAIM_KEY} ?? null;
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return in_array($value, ['guest', 'user', 'admin'], true) ? $value : null;
    }

    /** Never 'admin' — see the call site in resolveUser()'s docblock for why only this system-wide fallback, not an explicit per-identity medinv_level claim, stays capped. */
    private function clampedDefaultLevel(): string
    {
        $level = SystemSetting::get('oidc.default_level', 'user');

        return in_array($level, ['guest', 'user'], true) ? $level : 'user';
    }

    private function failure(Request $request, string $code, string $message, array $context = []): RedirectResponse
    {
        $this->logApiError($request, $code, $message, context: $context);

        return redirect($this->frontendUrl('/login?oidc_error='.$code));
    }
}
