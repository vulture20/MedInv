<?php

namespace Tests\Support;

use App\Models\SystemSetting;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

/**
 * Shared fixture-building for OidcClientTest and OidcAuthTest: a real RSA
 * keypair generated per test (not a fixture file, so tests never share
 * cross-run state) and helpers to sign an ID token with it and to
 * Http::fake() a standards-shaped discovery document/JWKS/token endpoint
 * around it — a real signature verified against a real JWKS is a
 * meaningfully stronger test than mocking OidcClient::verifyIdToken()
 * itself would be.
 */
trait OidcTestSupport
{
    private const TEST_ISSUER = 'https://idp.example.test';

    private const TEST_CLIENT_ID = 'medinv-test-client';

    private const TEST_KID = 'test-key-1';

    /** @return array{private_key: string, jwk: array} */
    private function generateOidcKeyPair(): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $privateKey);
        $details = openssl_pkey_get_details($resource);

        return [
            'private_key' => $privateKey,
            'jwk' => [
                'kty' => 'RSA',
                'kid' => self::TEST_KID,
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => $this->base64Url($details['rsa']['n']),
                'e' => $this->base64Url($details['rsa']['e']),
            ],
        ];
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** @param  array<string, mixed>  $claimOverrides Merged over a set of otherwise-valid default claims. */
    private function signOidcIdToken(array $keyPair, array $claimOverrides = []): string
    {
        $claims = array_merge([
            'iss' => self::TEST_ISSUER,
            'aud' => self::TEST_CLIENT_ID,
            'sub' => 'oidc-subject-123',
            'email' => 'person@example.test',
            'email_verified' => true,
            'name' => 'Person Example',
            'nonce' => 'expected-nonce',
            'iat' => time(),
            'exp' => time() + 300,
        ], $claimOverrides);

        return JWT::encode($claims, $keyPair['private_key'], 'RS256', self::TEST_KID);
    }

    /**
     * Configures the oidc.* system settings and fakes the three HTTP
     * endpoints OidcClient talks to, all consistent with self::TEST_ISSUER/
     * TEST_CLIENT_ID/the given keypair — real discovery-document-driven
     * URLs, not hardcoded ones, so a wrong URL anywhere in OidcClient would
     * actually break these tests instead of coincidentally still working.
     */
    private function fakeOidcProvider(array $keyPair, array $tokenResponseOverrides = []): void
    {
        SystemSetting::set('oidc.enabled', true);
        SystemSetting::set('oidc.issuer', self::TEST_ISSUER);
        SystemSetting::set('oidc.client_id', self::TEST_CLIENT_ID);
        SystemSetting::set('oidc.client_secret', 'test-client-secret');

        $authorizationEndpoint = self::TEST_ISSUER.'/authorize';
        $tokenEndpoint = self::TEST_ISSUER.'/token';
        $jwksUri = self::TEST_ISSUER.'/jwks';

        Http::fake([
            self::TEST_ISSUER.'/.well-known/openid-configuration' => Http::response([
                'issuer' => self::TEST_ISSUER,
                'authorization_endpoint' => $authorizationEndpoint,
                'token_endpoint' => $tokenEndpoint,
                'jwks_uri' => $jwksUri,
            ]),
            $jwksUri => Http::response(['keys' => [$keyPair['jwk']]]),
            $tokenEndpoint => Http::response(array_merge([
                'id_token' => $this->signOidcIdToken($keyPair),
                'access_token' => 'unused-access-token',
                'token_type' => 'Bearer',
            ], $tokenResponseOverrides)),
        ]);
    }
}
