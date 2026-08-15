<?php

namespace App\Domain\Security\Oidc;

/**
 * Thrown by OidcClient::verifyIdToken() for any check beyond signature/exp/
 * nbf (which firebase/php-jwt's JWT::decode() already enforces and reports
 * via its own exception types) — issuer mismatch, audience mismatch, or a
 * nonce that doesn't match the one this app generated for the request being
 * completed. OidcAuthController catches this (and JWT's own exceptions)
 * uniformly: the specific reason is logged server-side, but the browser
 * only ever sees a generic `oidc_failed` error_code — the distinction
 * matters for an admin debugging a misconfigured provider, not for
 * whoever is attempting to log in.
 */
class OidcVerificationException extends \RuntimeException {}
