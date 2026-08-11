<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level enforcement of the global user level (briefing 4.2), e.g.
 * `->middleware('level:admin')` for the administration area. Per-library
 * read access is a separate check, see LibraryPolicy / LibraryShare (4.3).
 */
class EnsureUserHasLevel
{
    public function handle(Request $request, Closure $next, string ...$levels): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->level, $levels, true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
