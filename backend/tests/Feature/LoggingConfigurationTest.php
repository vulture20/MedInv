<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
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

    public function test_api_requests_are_logged_with_method_path_and_ip(): void
    {
        Log::shouldReceive('info')->once()->with('Frontend access', Mockery::on(function (array $context) {
            return $context['method'] === 'GET'
                && $context['path'] === '/api/version'
                && array_key_exists('ip', $context);
        }));

        $this->getJson('/api/version');
    }
}
