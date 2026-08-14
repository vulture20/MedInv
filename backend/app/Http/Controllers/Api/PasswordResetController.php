<?php

namespace App\Http\Controllers\Api;

use App\Domain\Mail\MailStatusService;
use App\Http\Controllers\Controller;
use App\Rules\MedInvPasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

/**
 * Self-service, email-based password reset (briefing 12.3). Disabled
 * whenever the mail server is unreachable/misconfigured (12.2) — the
 * frontend greys the entry point out using AuthController::me()'s
 * mail_server_healthy flag, and this controller re-checks it server-side.
 * Consumed by the frontend's ForgotPasswordPage/ResetPasswordPage
 * (frontend/src/pages/password/).
 */
class PasswordResetController extends Controller
{
    public function __construct(private readonly MailStatusService $mailStatus) {}

    public function sendResetLink(Request $request)
    {
        $this->ensureMailHealthy($request);

        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        // Deliberately generic response so the endpoint can't be used to enumerate accounts.
        return response()->json(['message' => 'If that address exists, a reset link has been sent.']);
    }

    public function reset(Request $request)
    {
        $this->ensureMailHealthy($request);

        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', new MedInvPasswordPolicy],
        ]);

        $resetUser = null;
        $status = Password::reset($data, function ($user, $password) use (&$resetUser) {
            $user->forceFill(['password' => $password])->save();
            $resetUser = $user;
        });

        if ($status !== Password::PASSWORD_RESET) {
            // Laravel's PasswordBroker statuses (Password::INVALID_TOKEN/INVALID_USER/
            // RESET_THROTTLED) are translation keys into a lang/ directory this project
            // doesn't have (see CLAUDE.md: everything else uses an error_code, not
            // Laravel's own __($status) prose) — mapped onto our own error_code
            // convention instead so the frontend can translate them properly.
            $errorCode = match ($status) {
                Password::INVALID_TOKEN => 'invalid_token',
                Password::INVALID_USER => 'invalid_user',
                Password::RESET_THROTTLED => 'throttled',
                default => 'reset_failed',
            };

            return $this->resetError($request, $errorCode, "Password reset failed: {$status}");
        }

        // Credential change via self-service reset — a genuinely security-relevant
        // event previously only logged on *failure* (invalid token, throttled, ...),
        // never when it actually succeeded.
        Log::info('Password reset completed', ['user_id' => $resetUser?->id, 'email' => $data['email'], 'ip' => $request->ip()]);

        return response()->json(['message' => 'Password has been reset.']);
    }

    private function ensureMailHealthy(Request $request): void
    {
        if (! $this->mailStatus->isHealthy()) {
            $message = 'Password reset is unavailable: mail server is not configured or unreachable.';
            $this->logApiError($request, 'mail_unavailable', $message);

            abort(response()->json(['error_code' => 'mail_unavailable', 'message' => $message], 503));
        }
    }

    private function resetError(Request $request, string $code, string $message): JsonResponse
    {
        $this->logApiError($request, $code, $message);

        return response()->json(['error_code' => $code, 'message' => $message], 422);
    }
}
