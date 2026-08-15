<?php

namespace Tests\Feature;

use App\Domain\Security\Oidc\OidcClient;
use App\Domain\Security\Oidc\OidcVerificationException;
use App\Models\SystemSetting;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\OidcTestSupport;
use Tests\TestCase;

/**
 * GitHub issue #16. Covers OidcClient in isolation — request-building,
 * discovery/JWKS caching, and (the part actually worth real cryptographic
 * coverage, not a mock) ID token verification against a real RSA-signed
 * token and a real JWKS built from the matching public key. The full
 * redirect->callback HTTP flow, including account resolution, is
 * OidcAuthTest's job.
 */
class OidcClientTest extends TestCase
{
    use OidcTestSupport, RefreshDatabase;

    public function test_is_enabled_requires_every_setting_to_be_present(): void
    {
        $client = app(OidcClient::class);
        $this->assertFalse($client->isEnabled());

        SystemSetting::set('oidc.enabled', true);
        $this->assertFalse($client->isEnabled(), 'still false with no issuer/client_id/client_secret');

        SystemSetting::set('oidc.issuer', 'https://idp.example.test');
        SystemSetting::set('oidc.client_id', 'x');
        SystemSetting::set('oidc.client_secret', 'y');
        $this->assertTrue($client->isEnabled());
    }

    public function test_is_enabled_is_false_when_the_enabled_flag_itself_is_off(): void
    {
        SystemSetting::set('oidc.issuer', 'https://idp.example.test');
        SystemSetting::set('oidc.client_id', 'x');
        SystemSetting::set('oidc.client_secret', 'y');

        $this->assertFalse(app(OidcClient::class)->isEnabled());
    }

    public function test_provider_name_falls_back_to_a_generic_default(): void
    {
        $this->assertSame('Single Sign-On', app(OidcClient::class)->providerName());

        SystemSetting::set('oidc.provider_name', 'Pocket ID');
        $this->assertSame('Pocket ID', app(OidcClient::class)->providerName());
    }

    public function test_authorization_request_url_contains_the_expected_standard_parameters(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);

        $auth = app(OidcClient::class)->buildAuthorizationRequest();

        $this->assertStringStartsWith('https://idp.example.test/authorize?', $auth['url']);
        parse_str(parse_url($auth['url'], PHP_URL_QUERY), $params);

        $this->assertSame('code', $params['response_type']);
        $this->assertSame('medinv-test-client', $params['client_id']);
        $this->assertSame(url('/api/auth/oidc/callback'), $params['redirect_uri']);
        $this->assertStringContainsString('openid', $params['scope']);
        $this->assertSame($auth['state'], $params['state']);
        $this->assertSame($auth['nonce'], $params['nonce']);
        $this->assertSame('S256', $params['code_challenge_method']);

        // The challenge must be the base64url(sha256(verifier)) transform (RFC 7636) — recomputing it here
        // and comparing, rather than merely asserting the field is non-empty, catches a wrong hash/encoding.
        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $auth['code_verifier'], true)), '+/', '-_'), '=');
        $this->assertSame($expectedChallenge, $params['code_challenge']);
    }

    public function test_discovery_document_is_cached_across_calls(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        $client = app(OidcClient::class);

        $client->discoveryDocument();
        $client->discoveryDocument();
        $client->buildAuthorizationRequest(); // also calls discoveryDocument() internally

        Http::assertSentCount(1);
    }

    public function test_exchange_code_for_tokens_posts_the_expected_form_fields(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);

        app(OidcClient::class)->exchangeCodeForTokens('the-auth-code', 'the-code-verifier');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://idp.example.test/token'
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'the-auth-code'
                && $request['code_verifier'] === 'the-code-verifier'
                && $request['client_id'] === 'medinv-test-client'
                && $request['client_secret'] === 'test-client-secret'
                && $request['redirect_uri'] === url('/api/auth/oidc/callback');
        });
    }

    public function test_verify_id_token_accepts_a_validly_signed_token(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        $token = $this->signOidcIdToken($keyPair, ['nonce' => 'the-right-nonce']);

        $claims = app(OidcClient::class)->verifyIdToken($token, 'the-right-nonce');

        $this->assertSame('oidc-subject-123', $claims->sub);
        $this->assertSame('person@example.test', $claims->email);
    }

    public function test_verify_id_token_rejects_a_token_signed_by_an_unrelated_key(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        $unrelatedKeyPair = $this->generateOidcKeyPair();
        $token = $this->signOidcIdToken($unrelatedKeyPair, ['nonce' => 'n']);

        $this->expectException(SignatureInvalidException::class);
        app(OidcClient::class)->verifyIdToken($token, 'n');
    }

    public function test_verify_id_token_rejects_an_expired_token(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        $token = $this->signOidcIdToken($keyPair, ['nonce' => 'n', 'iat' => time() - 3600, 'exp' => time() - 1800]);

        $this->expectException(ExpiredException::class);
        app(OidcClient::class)->verifyIdToken($token, 'n');
    }

    public function test_verify_id_token_rejects_a_mismatched_issuer(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        $token = $this->signOidcIdToken($keyPair, ['nonce' => 'n', 'iss' => 'https://a-different-idp.example.test']);

        $this->expectException(OidcVerificationException::class);
        app(OidcClient::class)->verifyIdToken($token, 'n');
    }

    public function test_verify_id_token_rejects_a_mismatched_audience(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        $token = $this->signOidcIdToken($keyPair, ['nonce' => 'n', 'aud' => 'some-other-client']);

        $this->expectException(OidcVerificationException::class);
        app(OidcClient::class)->verifyIdToken($token, 'n');
    }

    public function test_verify_id_token_rejects_a_mismatched_nonce(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        $token = $this->signOidcIdToken($keyPair, ['nonce' => 'the-actual-nonce']);

        $this->expectException(OidcVerificationException::class);
        app(OidcClient::class)->verifyIdToken($token, 'a-different-nonce-than-what-was-sent');
    }

    public function test_verify_id_token_accepts_an_audience_given_as_an_array(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        $token = $this->signOidcIdToken($keyPair, ['nonce' => 'n', 'aud' => ['medinv-test-client', 'some-other-audience']]);

        $claims = app(OidcClient::class)->verifyIdToken($token, 'n');
        $this->assertSame('oidc-subject-123', $claims->sub);
    }
}
