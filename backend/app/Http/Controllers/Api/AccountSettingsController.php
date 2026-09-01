<?php

namespace App\Http\Controllers\Api;

use App\Domain\Users\UserDeletionService;
use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Models\User;
use App\Rules\MedInvPasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * The logged-in user's own preferences (briefing 4.1: "benutzerdefinierte
 * Einstellungen", e.g. preferred template/language, editable from the user
 * menu). Distinct from UserController, which is admin-only account
 * management of *other* users — destroy() below is this controller's one
 * exception, letting a user manage their *own* account's lifecycle rather
 * than just its preferences (GitHub issue #86).
 */
class AccountSettingsController extends Controller
{
    public function __construct(private readonly UserDeletionService $userDeletionService) {}

    public function update(Request $request)
    {
        // GitHub issue #11: 'light'/'dark' (the two bundled templates,
        // frontend/src/index.css) plus any code with a `templates` row —
        // admin-added or one of the repo-shipped templates/*.json files
        // (BundledTemplateRegistry), it makes no difference here, since
        // both end up as the same kind of row. Computed fresh on every
        // request rather than cached, same reasoning as
        // AdminSettingsController::updateLocale()'s identical fix: this
        // setting is small and rarely changed, so the extra query per save
        // isn't worth optimizing away, and a since-deleted template can't
        // still be picked.
        $allowedTemplates = [...['light', 'dark'], ...Template::query()->pluck('code')->all()];

        $data = $request->validate([
            'preferred_language' => ['sometimes', 'string', 'max:10'],
            'preferred_template' => ['sometimes', Rule::in($allowedTemplates)],
            // GitHub issue #194 — see User::ITEMS_PER_PAGE_OPTIONS's own docblock.
            'items_per_page' => ['sometimes', Rule::in(User::ITEMS_PER_PAGE_OPTIONS)],
        ]);

        $user = $request->user();
        $user->update($data);

        return $user;
    }

    /**
     * Self-service password change (GitHub issue #174, briefing 4.1/12.1) —
     * previously the only way to change a password at all was the
     * email-based "forgot password" flow (PasswordResetController), which
     * also needs a configured mail server; an admin can't set another
     * user's password after account creation either (UserController::
     * update() has no password field). Requires the current password
     * (verified via Hash::check() against the still-hashed column, never
     * decrypted) rather than trusting the session alone, the same
     * "prove you still are who you say you are" principle a password
     * change should have even though the request is already
     * authenticated — otherwise anyone with a few minutes at an unlocked,
     * still-logged-in session could lock the real owner out.
     *
     * An OIDC-provisioned account (`oidc_subject` set) has no local
     * password its owner could ever know — OidcAuthController::
     * findOrCreateUser() deliberately assigns it a random, never-revealed
     * one (see that method's own comment) specifically so only the SSO
     * flow or an admin-initiated reset can authenticate it. `current_password`
     * would therefore never validate for such an account; the frontend
     * hides this section entirely for one rather than let it 422 with a
     * confusing "wrong password" for a password that was never really
     * theirs to know — this is the same backstop AccountSettingsController
     * already needs regardless of what the frontend shows, since a request
     * could bypass that UI.
     */
    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', new MedInvPasswordPolicy],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            $this->logApiError($request, 'invalid_current_password', 'Password change failed: current password did not match.');

            return response()->json([
                'error_code' => 'invalid_current_password',
                'message' => 'The current password is incorrect.',
            ], 422);
        }

        // forceFill() rather than plain update() — mirrors
        // PasswordResetController::reset()'s own equivalent line; 'password'
        // is fillable regardless (#[Fillable] on User), so this isn't about
        // bypassing mass-assignment protection, just matching the
        // established convention for "this is a credential change, not an
        // ordinary attribute update" at every place this app sets a
        // password outside of initial account creation.
        $user->forceFill(['password' => $data['password']])->save();

        Log::info('Password changed (self-service)', ['user_id' => $user->id, 'email' => $user->email, 'ip' => $request->ip()]);

        return response()->noContent();
    }

    /**
     * Self-service account deletion (GitHub issue #86, a privacy-review
     * finding this session — the right of erasure — rather than a briefing
     * chapter): previously only an admin could remove an account at all
     * (UserController::destroy()) — a user who no longer wants to use the
     * application had no way to remove their own data without asking one.
     * Enforces the exact same two rules that path does (UserDeletionService)
     * — the predefined admin account can't delete itself either, and an
     * account still owning libraries must transfer ownership or delete
     * them first, same as an admin would have to do on that account's
     * behalf.
     *
     * Logs the account out *before* deleting it — same sequence
     * AuthController::logout() uses, but the ordering here matters in a way
     * it doesn't there: SessionGuard::logout() cycles the user's remember-
     * me token, which calls $user->save() on the exact same model instance
     * $request->user() already resolved (confirmed live via
     * spl_object_id()) — Eloquent's save() checks $this->exists to decide
     * insert vs. update, and delete() already flips that to false. Deleting
     * first and logging out after therefore didn't throw or no-op, it
     * silently *resurrected* the just-deleted row via a fresh INSERT
     * (same id, freshly-rotated remember_token) the instant logout() ran —
     * confirmed live by tracing the actual SQL log, not just by reasoning
     * about it. Logging out first avoids ever calling save() on a
     * non-existent row in the first place, so the eventual delete() below
     * is the one that actually sticks.
     */
    public function destroy(Request $request)
    {
        $user = $request->user();
        $blockingReason = $this->userDeletionService->blockingReasonFor($user);

        if ($blockingReason) {
            $this->logApiError($request, $blockingReason['error_code'], $blockingReason['message']);

            return response()->json($blockingReason, 422);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Log::info('User deleted (self-service)', ['user_id' => $user->id, 'email' => $user->email]);
        // GitHub issue #222: purges the user's other sessions (e.g. a
        // second device/browser still logged in) alongside the account
        // itself — the session()->invalidate() call above only ever
        // covers the current one.
        $this->userDeletionService->delete($user);

        return response()->noContent();
    }
}
