<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reset email was reported as showing Laravel's own branding — a
 * "logo" reading "Laravel" and a "© Laravel" footer — instead of MedInv's,
 * and being English-only. Root cause: User::sendPasswordResetNotification()
 * used to be Laravel's default, which sends Illuminate\Auth\Notifications\
 * ResetPassword through Laravel's built-in markdown mail theme; that theme
 * renders config('app.name') as both the header text and the footer
 * copyright, and this project never sets APP_NAME (see CLAUDE.md's
 * MEDINV_-prefix convention), so it fell back to Laravel's own literal
 * default of "Laravel". Fixed by replacing it with PasswordResetMail, a
 * self-contained, bilingual, MedInv-branded Mailable — see that class's
 * docblock.
 */
class PasswordResetMailTest extends TestCase
{
    use RefreshDatabase;

    private function render(): string
    {
        $user = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

        return (new PasswordResetMail($user, 'a-real-token'))->render();
    }

    public function test_subject_is_bilingual(): void
    {
        $user = User::factory()->create();
        $mail = new PasswordResetMail($user, 'a-real-token');

        $subject = $mail->envelope()->subject;

        $this->assertStringContainsString('Passwort zurücksetzen', $subject);
        $this->assertStringContainsString('Reset your password', $subject);
    }

    public function test_body_contains_both_german_and_english_content(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('Passwort zurücksetzen', $html);
        $this->assertStringContainsString('für Ihr MedInv-Konto', $html);
        $this->assertStringContainsString('Reset your password', $html);
        $this->assertStringContainsString('A request was received', $html);
    }

    public function test_body_shows_medinv_branding_not_laravel(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('MedInv', $html);
        $this->assertStringNotContainsString('Laravel', $html);
    }

    public function test_footer_copyright_is_medinv(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('&copy; '.date('Y').' MedInv', $html);
    }

    public function test_body_embeds_the_medinv_logo_as_a_data_uri(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringContainsString('alt="MedInv"', $html);
    }

    public function test_reset_url_appears_in_the_body(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);
        $mail = new PasswordResetMail($user, 'a-real-token');

        $html = $mail->render();

        // Blade HTML-escapes the URL's `&` as `&amp;` when rendering it into the href attribute/link text.
        $this->assertStringContainsString(htmlspecialchars($mail->resetUrl), $html);
        $this->assertStringContainsString('token=a-real-token', $mail->resetUrl);
        $this->assertStringContainsString('email='.urlencode('jane@example.com'), $mail->resetUrl);
    }
}
