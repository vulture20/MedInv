<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

abstract class Controller
{
    /**
     * Shared {error_code, message} 422 JSON response (GitHub issue #198,
     * CLAUDE.md's own documented "API errors carry a machine-readable
     * error_code" convention) — logs it via logApiError() first, so a
     * caller doesn't have to hand-roll the same two lines every existing
     * one-off version of this already did (UserController::
     * protectedAccountResponse(), AdminSettingsController::mailError()).
     * Deliberately not used to replace those two pre-existing call sites —
     * this is only for new call sites from here on, to avoid an
     * unrelated refactor of already-working, already-tested code.
     */
    protected function errorResponse(Request $request, string $errorCode, string $message, int $status = 422): JsonResponse
    {
        $this->logApiError($request, $errorCode, $message);

        return response()->json(['error_code' => $errorCode, 'message' => $message], $status);
    }

    /**
     * Logs a user-facing API error (the {error_code, message} shape from
     * CLAUDE.md's "API errors carry a machine-readable error_code"
     * convention) together with the requesting client's IP, so an admin
     * investigating a report ("I can't log in", "the test mail failed")
     * finds the error code, message and origin IP side by side in
     * storage/logs/laravel.log instead of only the client-side toast.
     *
     * $context merges in caller-specific details beyond error_code/ip —
     * e.g. AuthController::loginError() adds the attempted email, so a
     * failed-login or account-locked audit entry says *who* was targeted,
     * not just that some login attempt from a given IP failed.
     */
    protected function logApiError(Request $request, string $errorCode, string $message, string $level = 'warning', array $context = []): void
    {
        Log::log($level, $message, [
            'error_code' => $errorCode,
            'ip' => $request->ip(),
            ...$context,
        ]);
    }

    /**
     * Strips everything that isn't filesystem-safe across every OS a
     * generated download could land on, collapsing runs of it into a
     * single "-" (e.g. "Sample Library – CDs" -> "Sample-Library-CDs").
     * Keeps any Unicode letter/number (`\p{L}`/`\p{N}`, not just ASCII
     * `A-Za-z0-9`) — library/report names are free text in whatever script
     * an admin chose (this app is translated into a dozen languages,
     * briefing 10./11.4), and letters like "ü" or "Ω" or non-Latin scripts
     * are just as filesystem-safe as ASCII ones; only punctuation/symbols
     * (the actually unsafe part, e.g. `/`, `:`, `*`) needs stripping.
     *
     * Originally private to ExportImportController (GitHub issues #31/#43);
     * promoted here (GitHub issue #87) once LibraryController/
     * ReportsController's PDF export filenames needed the exact same logic.
     */
    protected function sanitizeForFilename(string $name): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', $name) ?? '', '-') ?: 'library';
    }
}
