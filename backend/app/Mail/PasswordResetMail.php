<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Password reset link (briefing 12.3). Replaces Laravel's built-in
 * ResetPassword notification (see User::sendPasswordResetNotification())
 * — that default sends Laravel's own markdown mail theme, whose header
 * "logo" and footer copyright both render `config('app.name')`. This
 * project deliberately never sets `APP_NAME` anywhere (CLAUDE.md: every env
 * var this app reads is `MEDINV_`-prefixed; `APP_NAME` is a stock Laravel
 * key it doesn't use), so that fell back to Laravel's own literal default
 * of "Laravel" — reported as a real user-facing bug (a reset email
 * branded as Laravel's, not MedInv's) rather than something cosmetic to
 * shrug off. The built-in template is also English-only.
 *
 * Self-contained HTML in the same style as UserInvitationMail (no Laravel
 * markdown mail components), with the actual MedInv logo
 * (resources/mail/logo.svg, the same file frontend/src/assets/logo.svg
 * ships — inlined as a data URI so no external asset host is needed and it
 * still shows even when the recipient's client blocks remote images) and
 * bilingual (German + English) content: unlike the logged-in SPA, an
 * anonymous "forgot password" request has no reliable locale to pick from —
 * User::preferred_language exists, but nothing guarantees the person typing
 * an email address into the forgot-password form is the account owner
 * reading in their configured language — so both languages are shown
 * rather than guessing one.
 */
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public readonly string $resetUrl;

    public function __construct(public readonly User $user, public readonly string $token)
    {
        // Hand-built literal URL pointing at the frontend SPA's own
        // /password/reset route, not Laravel's route('password.reset', ...)
        // — no such Laravel route exists (the SPA, not Laravel, owns that
        // path; see routes/web.php), so building this the way Laravel's own
        // ResetPassword::toMail() fallback does would throw
        // RouteNotFoundException. config('app.url') is the same "public URL
        // this instance is reachable at" value docker/entrypoint.sh derives
        // from MEDINV_URL and UserInvitationMail already uses.
        $this->resetUrl = sprintf(
            '%s/password/reset?token=%s&email=%s',
            rtrim(config('app.url'), '/'),
            $this->token,
            urlencode($this->user->email),
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'MedInv – Passwort zurücksetzen / Reset your password',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.password-reset',
            with: [
                'name' => $this->user->name,
                'resetUrl' => $this->resetUrl,
                'expireMinutes' => config('auth.passwords.users.expire'),
                'logoDataUri' => 'data:image/svg+xml;base64,'.base64_encode(
                    file_get_contents(resource_path('mail/logo.svg'))
                ),
            ],
        );
    }
}
