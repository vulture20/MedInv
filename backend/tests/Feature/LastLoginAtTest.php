<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\OidcTestSupport;
use Tests\TestCase;

/**
 * GitHub issue #181: `last_login_at`, feeding UsersPage.tsx's admin user
 * table. Set from both real login entry points — AuthController::login()
 * (email/password) and OidcAuthController::callback() (SSO) — but never
 * touchable through User::$fillable (UserController::store()/update()'s
 * own request-validation whitelists already can't set it either way, this
 * is defense-in-depth matching created_at/updated_at's own precedent
 * elsewhere in this app).
 */
class LastLoginAtTest extends TestCase
{
    use OidcTestSupport, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_fresh_account_has_no_last_login_at(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);

        $this->assertNull($user->last_login_at);
    }

    public function test_a_successful_password_login_sets_last_login_at(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true, 'password' => 'correct-password']);
        Carbon::setTestNow(Carbon::parse('2026-08-24 09:00:00'));

        $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'correct-password'], ['Origin' => 'http://localhost:5173']);

        $response->assertOk();
        $this->assertTrue(Carbon::parse('2026-08-24 09:00:00')->equalTo($user->fresh()->last_login_at));
    }

    public function test_a_failed_login_does_not_set_last_login_at(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true, 'password' => 'correct-password']);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password']);

        $this->assertNull($user->fresh()->last_login_at);
    }

    public function test_a_deactivated_account_login_attempt_does_not_set_last_login_at(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => false, 'password' => 'correct-password']);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'correct-password']);

        $this->assertNull($user->fresh()->last_login_at);
    }

    public function test_a_second_login_updates_last_login_at_to_the_newer_time(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true, 'password' => 'correct-password']);
        Carbon::setTestNow(Carbon::parse('2026-08-20 08:00:00'));
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'correct-password'], ['Origin' => 'http://localhost:5173']);

        Carbon::setTestNow(Carbon::parse('2026-08-24 09:30:00'));
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'correct-password'], ['Origin' => 'http://localhost:5173']);

        $this->assertTrue(Carbon::parse('2026-08-24 09:30:00')->equalTo($user->fresh()->last_login_at));
    }

    public function test_an_oidc_login_also_sets_last_login_at(): void
    {
        $keyPair = $this->generateOidcKeyPair();
        $this->fakeOidcProvider($keyPair);
        $user = User::factory()->create(['email' => 'person@example.test', 'is_active' => true, 'oidc_subject' => null]);
        Carbon::setTestNow(Carbon::parse('2026-08-24 09:00:00'));

        $response = $this->withSession(['oidc.state' => 'the-state', 'oidc.nonce' => 'expected-nonce', 'oidc.code_verifier' => 'the-verifier'])
            ->get('/api/auth/oidc/callback?state=the-state&code=auth-code-123');

        $response->assertRedirect();
        $this->assertTrue(Carbon::parse('2026-08-24 09:00:00')->equalTo($user->fresh()->last_login_at));
    }

    /** Defense-in-depth: not in User::$fillable, so even a hypothetical direct mass-assignment attempt through UserController::update() can't spoof it. */
    public function test_last_login_at_cannot_be_set_via_the_admin_user_update_endpoint(): void
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $target = User::factory()->create(['level' => 'user', 'is_active' => true]);

        $this->actingAs($admin)->putJson("/api/admin/users/{$target->id}", ['last_login_at' => now()->toIso8601String()]);

        $this->assertNull($target->fresh()->last_login_at);
    }
}
