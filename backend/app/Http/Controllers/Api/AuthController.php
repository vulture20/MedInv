<?php

namespace App\Http\Controllers\Api;

use App\Domain\Mail\MailStatusService;
use App\Domain\Security\BruteForceProtection;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Login/logout (briefing 11.1) via Sanctum SPA session auth. Applies the
 * brute-force throttle (12.4) and surfaces mail server health (12.2) so the
 * frontend can show the red admin warning and grey out password reset.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly BruteForceProtection $bruteForce,
        private readonly MailStatusService $mailStatus,
    ) {}

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($this->bruteForce->isLocked($credentials['email'])) {
            return $this->loginError($request, 'account_locked', 'Too many failed attempts. This account is temporarily locked.');
        }

        if (! Auth::attempt($credentials, remember: true)) {
            $this->bruteForce->recordFailure($credentials['email']);

            return $this->loginError($request, 'invalid_credentials', 'Invalid credentials.');
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();

            return $this->loginError($request, 'account_deactivated', 'This account has been deactivated.');
        }

        $this->bruteForce->clearFailures($credentials['email']);
        $request->session()->regenerate();

        return response()->json([
            'user' => $user,
            'mail_server_healthy' => $this->mailStatus->isHealthy(),
        ]);
    }

    /**
     * Returns a stable, machine-readable `error_code` alongside the
     * human-readable (English) `message`, instead of throwing
     * ValidationException — the frontend maps `error_code` to a translated
     * string via i18n (errors.* keys) rather than pattern-matching on
     * `message`, which would break the moment this text changes or a
     * non-English API consumer is added. Also logged with the client's IP
     * (see Controller::logApiError()) so a failed-login report can be
     * cross-checked against storage/logs/laravel.log.
     */
    private function loginError(Request $request, string $code, string $message): JsonResponse
    {
        $this->logApiError($request, $code, $message);

        return response()->json(['error_code' => $code, 'message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user(),
            'mail_server_healthy' => $this->mailStatus->isHealthy(),
        ]);
    }
}
