<?php

namespace App\Http\Controllers\Api;

use App\Domain\Mail\MailStatusService;
use App\Http\Controllers\Controller;
use App\Rules\MedInvPasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Self-service, email-based password reset (briefing 12.3). Disabled
 * whenever the mail server is unreachable/misconfigured (12.2) — the
 * frontend greys the entry point out using AuthController::me()'s
 * mail_server_healthy flag, and this controller re-checks it server-side.
 */
class PasswordResetController extends Controller
{
    public function __construct(private readonly MailStatusService $mailStatus) {}

    public function sendResetLink(Request $request)
    {
        $this->ensureMailHealthy();

        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        // Deliberately generic response so the endpoint can't be used to enumerate accounts.
        return response()->json(['message' => 'If that address exists, a reset link has been sent.']);
    }

    public function reset(Request $request)
    {
        $this->ensureMailHealthy();

        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', new MedInvPasswordPolicy],
        ]);

        $status = Password::reset($data, function ($user, $password) {
            $user->forceFill(['password' => $password])->save();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => 'Password has been reset.']);
    }

    private function ensureMailHealthy(): void
    {
        if (! $this->mailStatus->isHealthy()) {
            abort(503, 'Password reset is unavailable: mail server is not configured or unreachable.');
        }
    }
}
