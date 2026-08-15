<?php

namespace App\Domain\Security\Oidc;

use App\Models\SystemSetting;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OpenID Connect authorization-code-flow client (GitHub issue #16) — a
 * standard-OIDC implementation deliberately, not something Pocket-ID-
 * specific: every request this class makes (discovery, JWKS, token
 * exchange) follows the spec exactly, so any spec-compliant provider works
 * the same way (issue #16 explicitly asks for this, not a proprietary
 * integration). No third-party OAuth/OIDC client library is used for the
 * flow itself — building an authorization URL and POSTing a token exchange
 * is a handful of lines with Laravel's own Http facade, consistent with
 * how the rest of this codebase prefers explicit domain code over a
 * dependency for something this size (see e.g. FuzzyTextMatcher). The one
 * exception is ID token *signature verification*, which does use a real
 * library (firebase/php-jwt) rather than hand-rolled RSA/JWK parsing — that
 * is exactly the kind of cryptographic code this project shouldn't
 * reimplement itself.
 *
 * Configuration lives in system_settings (`oidc.*`, admin-configurable at
 * runtime via AdminSettingsController::updateOidc(), same pattern as the
 * mail settings) rather than a MEDINV_ env var, per CLAUDE.md's
 * "environment variables vs. runtime settings" distinction — this is
 * exactly the kind of thing an admin should be able to change without a
 * restart.
 */
class OidcClient
{
    /**
     * The discovery document and JWKS change only when the provider
     * rotates signing keys or reconfigures itself — not on every login —
     * so both are cached (Cache's default store is 'database', see
     * config/cache.php, so this is genuinely shared across php-fpm workers
     * rather than being reset every request). Keyed by issuer (not a
     * fixed key) so changing `oidc.issuer` at runtime naturally starts
     * fetching a fresh document instead of serving another provider's
     * stale one — no explicit cache invalidation needed on save.
     */
    private const CACHE_TTL_SECONDS = 3600;

    public function isEnabled(): bool
    {
        return (bool) SystemSetting::get('oidc.enabled', false)
            && filled(SystemSetting::get('oidc.issuer'))
            && filled(SystemSetting::get('oidc.client_id'))
            && filled(SystemSetting::get('oidc.client_secret'));
    }

    public function providerName(): string
    {
        return SystemSetting::get('oidc.provider_name') ?: 'Single Sign-On';
    }

    private function issuer(): string
    {
        return rtrim((string) SystemSetting::get('oidc.issuer'), '/');
    }

    /** @return array{authorization_endpoint: string, token_endpoint: string, jwks_uri: string, issuer?: string} */
    public function discoveryDocument(): array
    {
        $issuer = $this->issuer();

        return Cache::remember('oidc:discovery:'.md5($issuer), self::CACHE_TTL_SECONDS, function () use ($issuer) {
            return Http::timeout(10)->get("{$issuer}/.well-known/openid-configuration")->throw()->json();
        });
    }

    /** @return array{keys: array} */
    private function jwks(): array
    {
        $issuer = $this->issuer();
        $jwksUri = $this->discoveryDocument()['jwks_uri'];

        return Cache::remember('oidc:jwks:'.md5($issuer), self::CACHE_TTL_SECONDS, function () use ($jwksUri) {
            return Http::timeout(10)->get($jwksUri)->throw()->json();
        });
    }

    /**
     * Always the same, fixed callback URL for this instance — must match
     * exactly what an admin registers as the redirect URI on the provider
     * side. Built from config('app.url') (MEDINV_URL in Docker, see
     * CLAUDE.md), the same public-URL source of truth the rest of the app
     * already uses, rather than deriving it from the current request.
     */
    public function redirectUri(): string
    {
        return url('/api/auth/oidc/callback');
    }

    /**
     * Starts a login attempt: a fresh `state` (CSRF protection — verified
     * equal on callback), `nonce` (replay protection — verified against
     * the ID token's own `nonce` claim), and a PKCE `code_verifier`/
     * `code_challenge` pair (RFC 7636 — protects the authorization code
     * itself in transit, worthwhile even for a confidential client like
     * this one). The caller (OidcAuthController::redirect()) is
     * responsible for stashing state/nonce/code_verifier in the session
     * and sending the browser to `url`.
     *
     * @return array{url: string, state: string, nonce: string, code_verifier: string}
     */
    public function buildAuthorizationRequest(): array
    {
        $state = Str::random(40);
        $nonce = Str::random(40);
        $codeVerifier = Str::random(64); // a-zA-Z0-9 is already within PKCE's unreserved character set.
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $url = $this->discoveryDocument()['authorization_endpoint'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => SystemSetting::get('oidc.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return ['url' => $url, 'state' => $state, 'nonce' => $nonce, 'code_verifier' => $codeVerifier];
    }

    /** @return array{id_token: string} plus whatever else the provider returns (access_token, ...), unused here. */
    public function exchangeCodeForTokens(string $code, string $codeVerifier): array
    {
        return Http::asForm()->timeout(10)->post($this->discoveryDocument()['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => SystemSetting::get('oidc.client_id'),
            'client_secret' => SystemSetting::get('oidc.client_secret'),
            'code_verifier' => $codeVerifier,
        ])->throw()->json();
    }

    /**
     * Verifies the ID token's signature against the provider's own JWKS
     * (firebase/php-jwt validates the signature plus `exp`/`nbf`/`iat`
     * automatically and throws a specific exception per failure — see its
     * JWT::decode() docblock) and the claims Laravel-Sanctum-style session
     * auth alone can't check for us: `iss` must be this exact provider
     * (not some other issuer whose key happens to be in a shared JWKS —
     * not a real risk here since jwks() is always this provider's own
     * endpoint, but cheap to assert explicitly), `aud` must include our
     * `client_id` (an ID token minted for a *different* client must not be
     * accepted here), and `nonce` must match the one this app generated
     * for the authorization request being completed (replay protection —
     * without this, a captured ID token from a previous login could be
     * replayed against the callback endpoint).
     *
     * 'RS256' is passed as JWK::parseKeySet()'s default algorithm for any
     * key that doesn't declare its own `alg` (optional per RFC 7517) —
     * Pocket ID, and the OIDC spec's own baseline requirement, both use
     * RSA/RS256 signing.
     *
     * @throws OidcVerificationException|ExpiredException|SignatureInvalidException
     */
    public function verifyIdToken(string $idToken, string $expectedNonce): \stdClass
    {
        $claims = JWT::decode($idToken, JWK::parseKeySet($this->jwks(), 'RS256'));

        if (rtrim((string) ($claims->iss ?? ''), '/') !== $this->issuer()) {
            throw new OidcVerificationException('ID token issuer does not match the configured OIDC issuer.');
        }

        $audience = is_array($claims->aud ?? null) ? $claims->aud : [$claims->aud ?? null];
        if (! in_array((string) SystemSetting::get('oidc.client_id'), $audience, true)) {
            throw new OidcVerificationException('ID token audience does not include this client_id.');
        }

        if (($claims->nonce ?? null) !== $expectedNonce) {
            throw new OidcVerificationException('ID token nonce does not match the authorization request.');
        }

        return $claims;
    }
}
