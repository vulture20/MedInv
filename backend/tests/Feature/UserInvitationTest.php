<?php

namespace Tests\Feature;

use App\Mail\UserInvitationMail;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Covers UserController::store()'s optional send_invite flag. */
class UserInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        // is_active explicitly true: it's a DB-column default (see the
        // users migration), never set by the factory or by
        // Model::create()'s in-memory attributes, so actingAs() — which
        // hands the guard this exact instance rather than re-fetching from
        // the database like a real request would — would otherwise see it
        // as null/false and EnsureUserIsActive would (incorrectly) treat a
        // brand-new admin as deactivated.
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_creating_a_user_without_send_invite_sends_no_mail(): void
    {
        Mail::fake();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/users', [
            'name' => 'No Invite',
            'email' => 'no-invite@example.com',
            'password' => 'Str0ng!Passw0rd',
            'level' => 'user',
        ]);

        $response->assertCreated();
        $this->assertArrayNotHasKey('invite_sent', $response->json());
        Mail::assertNothingSent();
    }

    public function test_creating_a_user_with_send_invite_sends_the_invitation_mail(): void
    {
        Mail::fake();
        $this->configureMailServer();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Jane Invitee',
            'email' => 'jane-invitee@example.com',
            'password' => 'Str0ng!Passw0rd',
            'level' => 'user',
            'send_invite' => true,
        ]);

        $response->assertCreated();
        $this->assertTrue($response->json('invite_sent'));

        Mail::assertSent(UserInvitationMail::class, function (UserInvitationMail $mail) {
            $rendered = $mail->render();

            return $mail->user->email === 'jane-invitee@example.com'
                && str_contains($rendered, 'Jane Invitee')
                && str_contains($rendered, 'jane-invitee@example.com')
                && str_contains($rendered, config('app.url'));
        });
    }

    public function test_send_invite_without_a_configured_mail_server_does_not_block_user_creation(): void
    {
        Mail::fake();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/users', [
            'name' => 'No Mail Server',
            'email' => 'no-mail-server@example.com',
            'password' => 'Str0ng!Passw0rd',
            'level' => 'user',
            'send_invite' => true,
        ]);

        $response->assertCreated();
        $this->assertFalse($response->json('invite_sent'));
        $this->assertSame('not_configured', $response->json('invite_error'));
        Mail::assertNothingSent();
    }

    private function configureMailServer(): void
    {
        SystemSetting::set('mail.host', 'smtp.example.com');
        SystemSetting::set('mail.port', 587);
        SystemSetting::set('mail.encryption', 'starttls');
        SystemSetting::set('mail.from_address', 'medinv@example.com');
        SystemSetting::set('mail.from_name', 'MedInv');
    }
}
