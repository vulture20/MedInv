<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\OidcTestSupport;
use Tests\TestCase;

/**
 * GitHub issue #16: the full redirect -> (simulated provider) -> callback
 * HTTP flow, including account resolution/auto-provisioning policy. See
 * OidcClientTest for token-verification-specific coverage; this file
 * assumes a validly-signed token throughout and focuses on what
 * OidcAuthController does with it.
 *
 * callback() tests seed the session directly via withSession() rather
 * than chaining a real redirect() call first — deterministic and doesn't
 * depend on the test HTTP client's cross-request cookie behavior. The
 * genuine "does a session survive an actual external-domain redirect and
 * back" question is a browser-level concern, verified separately via a
 * live Playwright pass (not something a PHPUnit request/response cycle
 * can meaningfully exercise), see the session/CACHE note in
 * OidcAuthController's own docblock for why that leg needs the explicit
 * 'web' middleware group in the first place.
 */
class OidcAuthTest extends TestCase
{
    use OidcTestSupport, RefreshDatabase;

    private function callbackSession(string $nonce = 'expected-nonce'): array
    {
        return ['oidc.state' => 'the-state', 'oidc.nonce' => $nonce, 'oidc.code_verifier' => 'the-verifier'];
    }

    /** Mirrors OidcAuthController::frontendUrl() — see that method's docblock for why this isn't just plain redirect($path). */
    private function frontendUrl(string $path): string
    {
        return rtrim(env('FRONTEND_URL', config('app.url')), '/').$path;
    }

    public function test_config_endpoint_is_public_and_reports_enabled_state(): void
    {
        $this->getJson('/api/auth/oidc/config')
            ->assertOk()
            ->assertJson(['enabled' => false, 'provider_name' => 'Single Sign-On']);

        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        SystemSetting::set('oidc.provider_name', 'Pocket ID');

        $this->getJson('/api/auth/oidc/config')->assertOk()->assertJson(['enabled' => true, 'provider_name' => 'Pocket ID']);
    }

    public function test_redirect_404s_when_oidc_is_not_configured(): void
    {
        $this->get('/api/auth/oidc/redirect')->assertNotFound();
    }

    public function test_redirect_sends_the_browser_to_the_authorization_endpoint_and_stores_session_state(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);

        $response = $this->get('/api/auth/oidc/redirect');

        $response->assertRedirect();
        $this->assertStringStartsWith('https://idp.example.test/authorize?', $response->headers->get('Location'));
        $response->assertSessionHas('oidc.state');
        $response->assertSessionHas('oidc.nonce');
        $response->assertSessionHas('oidc.code_verifier');
    }

    public function test_callback_404s_when_oidc_is_not_configured(): void
    {
        $this->get('/api/auth/oidc/callback?state=x&code=y')->assertNotFound();
    }

    public function test_callback_logs_in_a_matching_existing_user_by_email_and_links_the_subject(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        $user = User::factory()->create(['email' => 'person@example.test', 'is_active' => true, 'oidc_subject' => null]);

        $response = $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $response->assertRedirect($this->frontendUrl('/'));
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('oidc-subject-123', $user->fresh()->oidc_subject);
    }

    public function test_callback_recognizes_an_already_linked_subject_even_if_the_email_has_since_changed(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        $user = User::factory()->create([
            'email' => 'a-completely-different-address@example.test',
            'is_active' => true,
            'oidc_subject' => 'oidc-subject-123',
        ]);

        $response = $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $response->assertRedirect($this->frontendUrl('/'));
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_callback_rejects_when_no_account_exists_and_auto_provision_is_disabled(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        // oidc.auto_provision defaults to false — not set here on purpose.

        $response = $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $response->assertRedirect($this->frontendUrl('/login?oidc_error=oidc_no_account'));
        $this->assertGuest();
        $this->assertDatabaseCount((new User)->getTable(), 0);
    }

    public function test_callback_auto_provisions_a_new_user_when_enabled(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        SystemSetting::set('oidc.auto_provision', true);
        SystemSetting::set('oidc.default_level', 'guest');

        $response = $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $response->assertRedirect($this->frontendUrl('/'));
        $user = User::query()->where('email', 'person@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('oidc-subject-123', $user->oidc_subject);
        $this->assertSame('guest', $user->level);
        $this->assertTrue($user->is_active);
        $this->assertSame('Person Example', $user->name);
    }

    public function test_auto_provisioned_level_can_never_become_admin_even_if_the_setting_is_tampered_with(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        SystemSetting::set('oidc.auto_provision', true);
        // Bypasses AdminSettingsController::updateOidc()'s Rule::in(['guest','user']) validation
        // entirely, simulating some other bug/path writing an unvalidated value — the clamp in
        // OidcAuthController::resolveUser() must catch this independently, not just trust validation.
        SystemSetting::set('oidc.default_level', 'admin');

        $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $user = User::query()->where('email', 'person@example.test')->firstOrFail();
        $this->assertSame('user', $user->level);
    }

    public function test_a_medinv_level_claim_grants_admin_even_when_auto_provisioning(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair, [
            'id_token' => $this->signOidcIdToken($keyPair, ['nonce' => 'expected-nonce', 'medinv_level' => 'admin']),
        ]);
        SystemSetting::set('oidc.auto_provision', true);
        // Deliberately left at the default ('user') — an explicit medinv_level
        // claim must win over it regardless of what the system-wide fallback says.

        $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $user = User::query()->where('email', 'person@example.test')->firstOrFail();
        $this->assertSame('admin', $user->level);
    }

    public function test_a_medinv_level_claim_updates_an_existing_users_level_on_every_login(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair, [
            'id_token' => $this->signOidcIdToken($keyPair, ['nonce' => 'expected-nonce', 'medinv_level' => 'admin']),
        ]);
        $user = User::factory()->create(['email' => 'person@example.test', 'is_active' => true, 'level' => 'user']);

        $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $this->assertSame('admin', $user->fresh()->level);
    }

    public function test_a_medinv_level_claim_can_downgrade_an_existing_admin(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair, [
            'id_token' => $this->signOidcIdToken($keyPair, ['nonce' => 'expected-nonce', 'medinv_level' => 'user']),
        ]);
        $user = User::factory()->create(['email' => 'person@example.test', 'is_active' => true, 'level' => 'admin']);

        $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $this->assertSame('user', $user->fresh()->level);
    }

    public function test_an_invalid_medinv_level_claim_value_is_ignored(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair, [
            'id_token' => $this->signOidcIdToken($keyPair, ['nonce' => 'expected-nonce', 'medinv_level' => 'superadmin']),
        ]);
        $user = User::factory()->create(['email' => 'person@example.test', 'is_active' => true, 'level' => 'user']);

        $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $this->assertSame('user', $user->fresh()->level);
    }

    public function test_a_medinv_level_claim_is_matched_case_insensitively_and_trimmed(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair, [
            'id_token' => $this->signOidcIdToken($keyPair, ['nonce' => 'expected-nonce', 'medinv_level' => ' Admin ']),
        ]);
        $user = User::factory()->create(['email' => 'person@example.test', 'is_active' => true, 'level' => 'user']);

        $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $this->assertSame('admin', $user->fresh()->level);
    }

    public function test_an_absent_medinv_level_claim_leaves_an_existing_users_level_unchanged(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair); // default token has no medinv_level claim at all
        $user = User::factory()->create(['email' => 'person@example.test', 'is_active' => true, 'level' => 'admin']);

        $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $this->assertSame('admin', $user->fresh()->level);
    }

    public function test_the_name_claim_syncs_an_existing_users_name_on_every_login(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair); // default token has name: 'Person Example'
        $user = User::factory()->create(['email' => 'person@example.test', 'is_active' => true, 'name' => 'Stale Old Name']);

        $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $this->assertSame('Person Example', $user->fresh()->name);
    }

    public function test_an_absent_name_claim_leaves_an_existing_users_name_unchanged(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair, [
            'id_token' => $this->signOidcIdToken($keyPair, ['nonce' => 'expected-nonce', 'name' => null]),
        ]);
        $user = User::factory()->create(['email' => 'person@example.test', 'is_active' => true, 'name' => 'Keep This Name']);

        $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $this->assertSame('Keep This Name', $user->fresh()->name);
    }

    public function test_a_medinv_name_claim_takes_precedence_over_the_standard_name_claim(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair, [
            'id_token' => $this->signOidcIdToken($keyPair, [
                'nonce' => 'expected-nonce', 'name' => 'Standard Claim Name', 'medinv_name' => 'Custom Claim Name',
            ]),
        ]);
        $user = User::factory()->create(['email' => 'person@example.test', 'is_active' => true, 'name' => 'Stale Old Name']);

        $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $this->assertSame('Custom Claim Name', $user->fresh()->name);
    }

    public function test_the_medinv_name_claim_is_used_when_auto_provisioning_too(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair, [
            'id_token' => $this->signOidcIdToken($keyPair, [
                'nonce' => 'expected-nonce', 'name' => 'Standard Claim Name', 'medinv_name' => 'Custom Claim Name',
            ]),
        ]);
        SystemSetting::set('oidc.auto_provision', true);

        $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $user = User::query()->where('email', 'person@example.test')->firstOrFail();
        $this->assertSame('Custom Claim Name', $user->name);
    }

    public function test_callback_rejects_auto_provisioning_from_an_unverified_email(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair, ['id_token' => $this->signOidcIdToken($keyPair, ['nonce' => 'expected-nonce', 'email_verified' => false])]);
        SystemSetting::set('oidc.auto_provision', true);

        $response = $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $response->assertRedirect($this->frontendUrl('/login?oidc_error=oidc_no_account'));
        $this->assertGuest();
        $this->assertDatabaseCount((new User)->getTable(), 0);
    }

    public function test_callback_rejects_login_for_a_deactivated_account(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        User::factory()->create(['email' => 'person@example.test', 'is_active' => false]);

        $response = $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $response->assertRedirect($this->frontendUrl('/login?oidc_error=oidc_account_deactivated'));
        $this->assertGuest();
    }

    public function test_callback_rejects_a_state_mismatch(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        User::factory()->create(['email' => 'person@example.test', 'is_active' => true]);

        $response = $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=a-completely-different-state&code=auth-code-123');

        $response->assertRedirect($this->frontendUrl('/login?oidc_error=oidc_state_mismatch'));
        $this->assertGuest();
    }

    public function test_callback_rejects_a_replayed_request_with_no_stored_session_state(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        User::factory()->create(['email' => 'person@example.test', 'is_active' => true]);

        // No withSession() at all — nothing was ever stored (or it was already consumed once).
        $response = $this->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $response->assertRedirect($this->frontendUrl('/login?oidc_error=oidc_state_mismatch'));
        $this->assertGuest();
    }

    public function test_callback_surfaces_a_provider_declined_error(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);

        $response = $this->withSession($this->callbackSession())
            ->get('/api/auth/oidc/callback?state=the-state&error=access_denied&error_description=User+cancelled');

        $response->assertRedirect($this->frontendUrl('/login?oidc_error=oidc_provider_error'));
        $this->assertGuest();
    }

    public function test_a_second_login_for_an_existing_linked_user_does_not_create_a_duplicate_account(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        $user = User::factory()->create(['email' => 'person@example.test', 'is_active' => true, 'oidc_subject' => null]);

        $this->withSession($this->callbackSession())->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');
        Auth::logout();
        $this->withSession($this->callbackSession())->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $this->assertDatabaseCount((new User)->getTable(), 1);
        $this->assertAuthenticatedAs($user->fresh());
    }
}
