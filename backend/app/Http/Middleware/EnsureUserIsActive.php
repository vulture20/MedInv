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
            // No auth()->guard('sanctum')->logout() here (there used to be one): the
            // 'sanctum' guard used by the `auth:sanctum` middleware is a stateless,
            // per-request RequestGuard, which has no logout() method at all — calling
            // it threw a BadMethodCallException, turning what should be a clean 403
            // into a 500 for every deactivated account's very next request. Rejecting
            // via the 403 below already prevents this and any further request from
            // reaching the route; a stateless per-request guard has nothing to "log
            // out" of the way a session guard would.
            // Same error_code an admin deactivating an account mid-session should be
            // handled identically to being rejected at login (AuthController::login()
            // uses the same 'account_deactivated' code) — see CLAUDE.md's "API errors
            // carry a machine-readable error_code" convention. The frontend's response
            // interceptor (api/client.ts) relies on this exact code rather than
            // pattern-matching the English message, per GitHub issue #5.
            return response()->json(['error_code' => 'account_deactivated', 'message' => 'Account is deactivated.'], 403);
        }

        return $next($request);
    }
}
