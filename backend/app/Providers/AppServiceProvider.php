<?php

namespace App\Providers;

use App\Models\SystemSetting;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Query parameters/header names that carry a secret across the metadata
     * providers (app/Domain/Metadata/Providers/) and must never reach the
     * log — case-insensitive. Most providers send their key via a header
     * (Authorization: Bearer .../Discogs token=..., or UpcMdbProvider's
     * x-api-key), which logOutgoingHttpRequests() below doesn't log at all,
     * but GoogleBooksProvider's is a `key` query parameter (Google's own
     * documented auth convention), which *is* part of the logged URL — this
     * list exists specifically so that isn't logged in the clear. Kept as a
     * small denylist rather than only covering `key` so a future provider
     * using one of the other common names is covered without anyone having
     * to remember to update this.
     */
    private const REDACTED_QUERY_PARAMS = ['key', 'api_key', 'apikey', 'token', 'access_token', 'secret'];

    /**
     * Bootstrap any application services.
     *
     * Applies admin-configured settings (system_settings) onto Laravel's
     * live config on every request — see applyLogLevel()/applyMailConfig()
     * below — instead of only whatever was baked in at container boot via
     * MEDINV_* env vars. Wrapped in try/catch because this runs before the
     * system_settings table is guaranteed to exist yet (e.g. during the
     * very first `migrate`).
     */
    public function boot(): void
    {
        // Doesn't touch the database at all, so registered unconditionally,
        // outside the try/catch below — unlike applyLogLevel()/
        // applyMailConfig(), there's no "not ready yet" state to guard against.
        $this->logOutgoingHttpRequests();

        try {
            if (! SystemSetting::query()->getConnection()->getSchemaBuilder()->hasTable((new SystemSetting)->getTable())) {
                return;
            }

            $this->applyLogLevel();
            $this->applyMailConfig();
        } catch (\Throwable) {
            // No DB connection yet (e.g. `artisan key:generate` before first migrate) — fall back to .env config.
        }
    }

    /**
     * Logs every outgoing call the metadata providers (app/Domain/Metadata/
     * Providers/) make — the `Http` facade isn't used anywhere else in this
     * app, confirmed by grepping for it, so this covers exactly "web
     * queries and their results" without needing per-provider logging calls
     * scattered across six classes (and automatically covering any future
     * provider too). Previously these calls were completely invisible in
     * the log, which is exactly what made GitHub issue #17 (a fresh install
     * silently returning "no_match" for every capture, root-caused to zero
     * enabled providers) hard to diagnose from the log alone.
     *
     * Uses Guzzle's `on_stats` option (set for every request via
     * Http::globalOptions(), applied before any provider-specific
     * ->withHeaders()/->withHeader() call layers on top) rather than
     * Laravel's RequestSending/ResponseReceived events — on_stats fires
     * exactly once per request with the request, the (nullable, absent on
     * a connection failure) response, and the transfer time all together,
     * without needing to correlate a separate "before" and "after" event
     * pair by hand. Reading the response body here is safe and doesn't
     * interfere with the provider's own later ->json()/->body() call: PSR-7
     * streams' __toString() seeks back to the start first if the stream is
     * seekable (confirmed against vendor/guzzlehttp/psr7/src/Stream.php),
     * and Laravel's Http\Client\Response::body() also reads via
     * __toString() — same safety net either read happens first.
     *
     * Deliberately Log::debug() with no extra gating, same reasoning as
     * LogFrontendAccess: AppServiceProvider::applyLogLevel() already applies
     * the admin-configured loglevel to the channel itself, so this is a
     * no-op write at any level above DEBUG.
     */
    private function logOutgoingHttpRequests(): void
    {
        Http::globalOptions([
            'on_stats' => function (TransferStats $stats) {
                $response = $stats->getResponse();

                Log::debug('Outgoing HTTP request (metadata provider)', [
                    'method' => $stats->getRequest()->getMethod(),
                    'url' => $this->redactSensitiveQueryParams((string) $stats->getEffectiveUri()),
                    'status' => $response?->getStatusCode(),
                    'duration_ms' => $stats->getTransferTime() !== null ? round($stats->getTransferTime() * 1000) : null,
                    // Truncated: a full-text search response body can run to several KB,
                    // and this is meant to help spot *why* a lookup didn't match, not to
                    // be a complete transcript.
                    'response_body' => $response ? Str::limit((string) $response->getBody(), 2000) : null,
                ]);
            },
        ]);
    }

    /** @see logOutgoingHttpRequests()'s docblock and REDACTED_QUERY_PARAMS. */
    private function redactSensitiveQueryParams(string $url): string
    {
        $parts = parse_url($url);

        if (empty($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);

        foreach ($query as $key => $value) {
            if (in_array(strtolower($key), self::REDACTED_QUERY_PARAMS, true)) {
                $query[$key] = '[REDACTED]';
            }
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';

        return $scheme.$host.$port.$path.'?'.http_build_query($query);
    }

    /**
     * Applies the admin-configured loglevel (briefing 15./16., stored in
     * system_settings via AdminSettingsController::updateLoglevel(), initial
     * value from MEDINV_LOGLEVEL) onto the actual log channel(s) — until
     * this existed, changing the loglevel in the admin UI updated the
     * stored value but never actually affected what Log::info() etc. wrote,
     * since config/logging.php's channel `level` is otherwise only ever set
     * from the LOG_LEVEL env var at container boot. Applied to both
     * `single` and `daily`, the two file-based channels MedInv ships with —
     * whichever one LOG_STACK/LOG_CHANNEL actually routes through picks it up.
     */
    private function applyLogLevel(): void
    {
        $level = strtolower(SystemSetting::get('loglevel', env('MEDINV_LOGLEVEL', 'WARNING')));

        config([
            'logging.channels.single.level' => $level,
            'logging.channels.daily.level' => $level,
        ]);
    }

    /**
     * Applies the admin-configured SMTP settings (briefing 12.2, stored in
     * system_settings via AdminSettingsController) onto Laravel's mail
     * config at runtime, so `Mail`/`Password::sendResetLink()` actually use
     * them instead of the .env-defined mailer.
     */
    private function applyMailConfig(): void
    {
        $host = SystemSetting::get('mail.host');

        if (! $host) {
            return;
        }

        $encryption = SystemSetting::get('mail.encryption', 'starttls');

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => SystemSetting::get('mail.port'),
            'mail.mailers.smtp.username' => SystemSetting::get('mail.username'),
            'mail.mailers.smtp.password' => SystemSetting::get('mail.password'),
            'mail.mailers.smtp.scheme' => $encryption === 'ssl_tls' ? 'smtps' : null,
            // 'none' (briefing 12.2) disables the opportunistic STARTTLS upgrade Symfony
            // Mailer otherwise attempts on a plain 'smtp' scheme — see EsmtpTransportFactory,
            // where auto_tls=false forces $tls=false instead of null (auto-negotiate).
            'mail.mailers.smtp.auto_tls' => $encryption !== 'none',
            'mail.from.address' => SystemSetting::get('mail.from_address'),
            'mail.from.name' => SystemSetting::get('mail.from_name'),
        ]);
    }
}
