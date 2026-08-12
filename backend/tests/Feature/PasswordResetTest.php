<?php

namespace Tests\Feature;

use App\Domain\Mail\MailStatusService;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Covers the "forgot password" flow reported as completely missing: the
 * frontend had no /password/forgot or /password/reset route at all (a blank
 * page), and even fixing that, the backend's reset-link generation crashed
 * with RouteNotFoundException because no `password.reset` Laravel route
 * exists — the frontend, not Laravel, owns that path. See
 * AppServiceProvider::applyPasswordResetUrl().
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    /** isReachable() does a real network connection attempt — not something a test should depend on. */
    private function mailHealthy(): void
    {
        $this->partialMock(MailStatusService::class, function ($mock) {
            $mock->shouldReceive('isHealthy')->andReturn(true);
        });
    }

    public function test_reset_link_notification_points_at_the_frontend_not_a_missing_laravel_route(): void
    {
        Notification::fake();
        $this->mailHealthy();
        $user = User::factory()->create();

        $this->postJson('/api/password/email', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $mail = $notification->toMail($user);

            return str_starts_with($mail->actionUrl, config('app.url').'/password/reset?token=')
                && str_contains($mail->actionUrl, 'email='.urlencode($user->email));
        });
    }

    public function test_send_reset_link_fails_cleanly_when_mail_is_not_configured(): void
    {
        $response = $this->postJson('/api/password/email', ['email' => 'someone@example.com']);

        $response->assertStatus(503);
        $this->assertSame('mail_unavailable', $response->json('error_code'));
    }

    public function test_reset_with_a_valid_token_updates_the_password(): void
    {
        $this->mailHealthy();
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $response = $this->postJson('/api/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('Str0ng!Passw0rd', $user->fresh()->password));
    }

    public function test_reset_with_an_invalid_token_returns_a_known_error_code(): void
    {
        $this->mailHealthy();
        $user = User::factory()->create();

        $response = $this->postJson('/api/password/reset', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
        ]);

        $response->assertStatus(422);
        $this->assertSame('invalid_token', $response->json('error_code'));
    }

    public function test_reset_fails_cleanly_when_mail_is_not_configured(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/password/reset', [
            'token' => 'whatever',
            'email' => $user->email,
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
        ]);

        $response->assertStatus(503);
        $this->assertSame('mail_unavailable', $response->json('error_code'));
    }
}
