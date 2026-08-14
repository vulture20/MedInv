<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs every request the frontend makes against the API, together with its
 * result — practically every user interaction with the SPA goes through
 * here (login, /me, data fetching), since the SPA's static HTML/JS/CSS
 * itself is served by nginx and never reaches Laravel at all (see
 * docker/nginx.conf.template and routes/web.php). Registered globally on
 * the `api` middleware group (bootstrap/app.php).
 *
 * DEBUG, not INFO: matches AppServiceProvider::logOutgoingHttpRequests()'s
 * "web queries and their results" logging for outgoing metadata-provider
 * calls — an admin who wants a full inbound+outbound HTTP trace sets the
 * loglevel to DEBUG for both at once, rather than the two halves of the
 * same picture living at two different levels. Deliberately just
 * Log::debug() with no extra gating beyond that: AppServiceProvider::
 * applyLogLevel() already applies the admin-configured loglevel (briefing
 * 15./16.) to the actual log channel, so these entries are only written to
 * the log file at all when the effective level is DEBUG — at any level
 * above that they're filtered out by Monolog itself, same as any other
 * debug()-level call.
 *
 * Wrapped in try/finally, not logged before calling $next($request) the
 * way this used to be: an uncaught exception deep in a controller must
 * still produce a log entry (with whatever duration elapsed before it
 * failed and `status: null`, since there's no response to report a code
 * from) rather than silently skipping the "access" log line for exactly
 * the requests most worth having a record of.
 */
class LogFrontendAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $status = null;

        try {
            $response = $next($request);
            $status = $response->getStatusCode();

            return $response;
        } finally {
            Log::debug('Frontend access', [
                'method' => $request->method(),
                'path' => '/'.$request->path(),
                'ip' => $request->ip(),
                'status' => $status,
                'duration_ms' => round((microtime(true) - $startedAt) * 1000),
            ]);
        }
    }
}
