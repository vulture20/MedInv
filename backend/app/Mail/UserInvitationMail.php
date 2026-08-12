<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Optional invitation sent when an admin creates a user with `send_invite`
 * (UserController::store()) — the first real Mailable in the project; every
 * other outgoing mail so far is either Mail::raw() (AdminSettingsController::
 * testMail()) or Laravel's built-in ResetPassword notification. Content is
 * fixed to what was asked for: the instance URL, the new account's name and
 * email address — deliberately not the password, which an admin sets and
 * shares out of band.
 */
class UserInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ihr MedInv-Zugang wurde eingerichtet',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.user-invitation',
            with: [
                'name' => $this->user->name,
                'email' => $this->user->email,
                'url' => config('app.url'),
            ],
        );
    }
}
