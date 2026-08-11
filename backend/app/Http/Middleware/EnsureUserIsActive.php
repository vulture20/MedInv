<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects requests from deactivated accounts (briefing 4.1: admins can
 * disable an account, which preserves its history/ownership but blocks
 * login — an alternative to actually deleting it via
 * UserController::destroy(), which is also possible for any account except
 * the predefined admin).
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            auth()->guard('sanctum')->logout();

            return response()->json(['message' => 'Account is deactivated.'], 403);
        }

        return $next($request);
    }
}
