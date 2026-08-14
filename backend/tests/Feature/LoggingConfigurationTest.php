<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

/**
 * Covers two gaps: the admin-configured loglevel was stored but never
 * actually applied to Laravel's logging config (AppServiceProvider::
 * applyLogLevel()), and there was no logging of frontend/API access at all
 * (LogFrontendAccess). Also pins the new default (WARNING, was INFO).
 */
class LoggingConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_loglevel_is_warning(): void
    {
        $this->assertSame('WARNING', SystemSetting::defaults()['loglevel']);
    }

    public function test_default_loglevel_is_applied_to_the_log_channel(): void
    {
        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('warning', config('logging.channels.single.level'));
    }

    public function test_admin_configured_loglevel_is_applied_to_the_log_channel(): void
    {
        SystemSetting::set('loglevel', 'INFO');

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('info', config('logging.channels.single.level'));
        $this->assertSame('info', config('logging.channels.daily.level'));
    }

    public function test_api_requests_are_logged_with_method_path_ip_status_and_duration(): void
    {
        Log::shouldReceive('debug')->once()->with('Frontend access', Mockery::on(function (array $context) {
            return $context['method'] === 'GET'
                && $context['path'] === '/api/version'
                && array_key_exists('ip', $context)
                && $context['status'] === 200
                && array_key_exists('duration_ms', $context);
        }));

        $this->getJson('/api/version');
    }

    /**
     * An uncaught exception must still produce a log entry (status: null,
     * since there's no response to report a code from) — LogFrontendAccess
     * used to log before calling $next($request), which happened to log
     * every request regardless of outcome for a different reason; moving
     * the log after $next() (to also report the response status/duration)
     * needed an explicit try/finally to keep that same guarantee.
     */
    public function test_a_request_that_throws_is_still_logged(): void
    {
        Route::middleware(['api'])->get('/test-throws', fn () => throw new \RuntimeException('boom'));

        Log::shouldReceive('debug')->once()->with('Frontend access', Mockery::on(function (array $context) {
            return $context['path'] === '/test-throws'
                && $context['status'] === null
                && array_key_exists('duration_ms', $context);
        }));

        $this->withoutExceptionHandling();
        try {
            $this->getJson('/test-throws');
        } catch (\RuntimeException) {
            // Expected — the point here is only that the log entry above still fired.
        }
    }
}
