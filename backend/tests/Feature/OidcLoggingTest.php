<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Support\OidcTestSupport;
use Tests\TestCase;

/**
 * OIDC login audit trail: a successful OIDC login previously went entirely
 * unlogged, unlike the password-based flow (see AuthLoggingTest). The
 * concise "who logged in, when" line stays at INFO — same as password
 * login, so both show up identically in an ordinary audit-level log — while
 * everything that explains *how* an account was resolved (which lookup
 * matched, what the medinv_name/medinv_level custom claims actually
 * contained) is DEBUG-only, useful while diagnosing a misconfigured
 * provider/claim mapping but too noisy for every ordinary login.
 *
 * Log::shouldReceive('debug')->zeroOrMoreTimes() is only added to tests that
 * don't themselves assert on a specific debug call — see AuthLoggingTest's
 * docblock for why that's necessary once any Log:: expectation is set at
 * all (LogFrontendAccess logs every request at DEBUG).
 */
class OidcLoggingTest extends TestCase
{
    use OidcTestSupport, RefreshDatabase;

    private function callbackSession(string $nonce = 'expected-nonce'): array
    {
        return ['oidc.state' => 'the-state', 'oidc.nonce' => $nonce, 'oidc.code_verifier' => 'the-verifier'];
    }

    public function test_a_successful_oidc_login_is_logged_at_info_with_user_id_email_and_ip(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        $user = User::factory()->create(['email' => 'person@example.test', 'is_active' => true, 'oidc_subject' => null]);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('User logged in via OIDC', Mockery::on(function ($context) use ($user) {
            return $context['user_id'] === $user->id
                && $context['email'] === $user->email
                && array_key_exists('ip', $context);
        }));

        $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123')
            ->assertRedirect();
    }

    public function test_resolving_an_existing_account_by_email_is_logged_at_debug_with_the_match_reason(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        $user = User::factory()->create(['email' => 'person@example.test', 'is_active' => true, 'oidc_subject' => null]);

        Log::shouldReceive('debug')->once()->with('OIDC login: resolved to an existing account', Mockery::on(function ($context) use ($user) {
            return $context['user_id'] === $user->id
                && $context['subject'] === 'oidc-subject-123'
                && $context['issuer'] === 'https://idp.example.test'
                && $context['matched_by'] === 'email';
        }));
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123')
            ->assertRedirect();
    }

    public function test_resolving_an_existing_account_by_subject_is_logged_at_debug_with_the_match_reason(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        $user = User::factory()->create(['email' => 'someone-else@example.test', 'is_active' => true, 'oidc_subject' => 'oidc-subject-123']);

        Log::shouldReceive('debug')->once()->with('OIDC login: resolved to an existing account', Mockery::on(function ($context) use ($user) {
            return $context['user_id'] === $user->id && $context['matched_by'] === 'oidc_subject';
        }));
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123')
            ->assertRedirect();
    }

    public function test_auto_provisioning_a_new_account_is_logged_at_debug(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        SystemSetting::set('oidc.auto_provision', true);

        Log::shouldReceive('debug')->once()->with('OIDC login: auto-provisioned a new account', Mockery::on(function ($context) {
            return $context['subject'] === 'oidc-subject-123'
                && $context['issuer'] === 'https://idp.example.test'
                && $context['level'] === 'user';
        }));
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123')
            ->assertRedirect();
    }

    public function test_declining_to_auto_provision_is_logged_at_debug(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        // oidc.auto_provision left at its default (false).

        Log::shouldReceive('debug')->once()->with('OIDC login: no matching account and not auto-provisioning', Mockery::on(function ($context) {
            return $context['subject'] === 'oidc-subject-123' && $context['auto_provision'] === false;
        }));
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        // resolveUser() returning null routes callback() through failure(), which
        // logs at 'warning' via Controller::logApiError()'s Log::log() call.
        Log::shouldReceive('log')->zeroOrMoreTimes();

        $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123')
            ->assertRedirect();
    }
}
