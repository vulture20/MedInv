<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

abstract class Controller
{
    /**
     * Logs a user-facing API error (the {error_code, message} shape from
     * CLAUDE.md's "API errors carry a machine-readable error_code"
     * convention) together with the requesting client's IP, so an admin
     * investigating a report ("I can't log in", "the test mail failed")
     * finds the error code, message and origin IP side by side in
     * storage/logs/laravel.log instead of only the client-side toast.
     */
    protected function logApiError(Request $request, string $errorCode, string $message, string $level = 'warning'): void
    {
        Log::log($level, $message, [
            'error_code' => $errorCode,
            'ip' => $request->ip(),
        ]);
    }
}
