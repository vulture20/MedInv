<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs every request the frontend makes against the API — practically every
 * user interaction with the SPA goes through here (login, /me, data
 * fetching), since the SPA's static HTML/JS/CSS itself is served by nginx
 * and never reaches Laravel at all (see docker/nginx.conf.template and
 * routes/web.php). Registered globally on the `api` middleware group
 * (bootstrap/app.php).
 *
 * Deliberately just Log::info() with no extra gating: AppServiceProvider::
 * applyLogLevel() already applies the admin-configured loglevel (briefing
 * 15./16.) to the actual log channel, so these entries are only written to
 * the log file at all when the effective level is DEBUG or INFO — at the
 * default WARNING they're filtered out by Monolog itself, same as any other
 * info()-level call.
 */
class LogFrontendAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        Log::info('Frontend access', [
            'method' => $request->method(),
            'path' => '/'.$request->path(),
            'ip' => $request->ip(),
        ]);

        return $next($request);
    }
}
