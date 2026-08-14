<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Login activity audit trail (AuthController): a successful login was
 * previously never logged at all, only failures — and even those only
 * carried the client IP and error_code, not the attempted email, making it
 * hard to tell *who* a given failed-login/account-locked entry was even
 * about.
 *
 * Log::shouldReceive('debug')->zeroOrMoreTimes() is present in every test
 * here alongside the specific expectation under test: LogFrontendAccess
 * logs every single request at DEBUG (including /api/login itself), and
 * once any Log:: method has an explicit expectation the facade is fully
 * swapped for a fake — an unrelated, unmocked method call on it throws
 * "no expectations were specified", which cascades into a confusing
 * failure once Laravel's own exception reporting then also tries (and
 * fails) to Log::error() *that*.
 */
class AuthLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_login_is_logged_with_user_id_email_and_ip(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true, 'password' => 'correct-password']);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('User logged in', Mockery::on(function ($context) use ($user) {
            return $context['user_id'] === $user->id
                && $context['email'] === $user->email
                && array_key_exists('ip', $context);
        }));

        // A stateful-matching Origin header is required for the success path,
        // which calls $request->session()->regenerate() — without it Sanctum's
        // EnsureFrontendRequestsAreStateful treats the request as a plain
        // stateless API call (no session middleware at all), and that call
        // throws "Session store not set on request." No existing test in this
        // suite exercised a *successful* login via the real HTTP endpoint
        // before this, so this gap had gone unnoticed.
        $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'correct-password'], ['Origin' => 'http://localhost:5173']);

        $response->assertOk();
    }

    public function test_a_failed_login_is_logged_with_the_attempted_email(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true, 'password' => 'correct-password']);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('log')->once()->with('warning', 'Invalid credentials.', Mockery::on(function ($context) use ($user) {
            return $context['error_code'] === 'invalid_credentials'
                && $context['email'] === $user->email
                && array_key_exists('ip', $context);
        }));

        $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password']);

        $response->assertStatus(422);
    }

    public function test_a_deactivated_account_login_attempt_is_logged_with_its_email(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => false, 'password' => 'correct-password']);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('log')->once()->with('warning', 'This account has been deactivated.', Mockery::on(function ($context) use ($user) {
            return $context['error_code'] === 'account_deactivated' && $context['email'] === $user->email;
        }));

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'correct-password']);
    }

    public function test_logging_in_as_a_nonexistent_email_still_logs_the_attempted_address(): void
    {
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('log')->once()->with('warning', 'Invalid credentials.', Mockery::on(function ($context) {
            return $context['error_code'] === 'invalid_credentials' && $context['email'] === 'nobody@example.com';
        }));

        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'whatever']);
    }
}
