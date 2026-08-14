<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * System-settings-change audit trail (AdminSettingsController): changing
 * mail/backup/security/cover-cleanup/loglevel/timezone/locale settings was
 * previously never logged at all. See AuthLoggingTest's docblock for why
 * every test here also loosely allows Log::debug() (LogFrontendAccess's
 * per-request entry).
 */
class AdminSettingsLoggingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_updating_mail_settings_is_logged_with_the_password_redacted(): void
    {
        $admin = $this->actingAsAdmin();

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Settings updated', Mockery::on(function ($context) use ($admin) {
            return $context['actor_id'] === $admin->id
                && $context['category'] === 'mail'
                && $context['changes']['host'] === 'smtp.example.com'
                && $context['changes']['password'] === '[REDACTED]';
        }));

        $this->putJson('/api/admin/settings/mail', [
            'host' => 'smtp.example.com', 'port' => 587, 'password' => 'TOTALLY-SECRET-SMTP-PASSWORD',
            'encryption' => 'starttls', 'from_address' => 'noreply@example.com', 'from_name' => 'MedInv',
        ])->assertOk();
    }

    public function test_a_null_mail_password_is_left_as_is_not_redacted_into_a_string(): void
    {
        $admin = $this->actingAsAdmin();

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Settings updated', Mockery::on(function ($context) {
            return array_key_exists('password', $context['changes']) && $context['changes']['password'] === null;
        }));

        $this->putJson('/api/admin/settings/mail', [
            'host' => 'smtp.example.com', 'port' => 587, 'password' => null,
            'encryption' => 'none', 'from_address' => 'noreply@example.com', 'from_name' => 'MedInv',
        ])->assertOk();
    }

    public function test_updating_backup_settings_is_logged(): void
    {
        $admin = $this->actingAsAdmin();

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Settings updated', Mockery::on(function ($context) use ($admin) {
            return $context['actor_id'] === $admin->id
                && $context['category'] === 'backup'
                && $context['changes']['interval_mode'] === 'weekly';
        }));

        $this->putJson('/api/admin/settings/backup', ['interval_mode' => 'weekly'])->assertOk();
    }

    public function test_updating_security_settings_is_logged(): void
    {
        $admin = $this->actingAsAdmin();

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Settings updated', Mockery::on(function ($context) use ($admin) {
            return $context['actor_id'] === $admin->id && $context['category'] === 'security' && $context['changes']['throttle_max_attempts'] === 10;
        }));

        $this->putJson('/api/admin/settings/security', [
            'throttle_max_attempts' => 10, 'throttle_window_minutes' => 5, 'throttle_lock_minutes' => 30,
        ])->assertOk();
    }

    public function test_updating_cover_cleanup_setting_is_logged(): void
    {
        $admin = $this->actingAsAdmin();

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Settings updated', Mockery::on(function ($context) use ($admin) {
            return $context['actor_id'] === $admin->id && $context['category'] === 'covers' && $context['changes']['cleanup_enabled'] === false;
        }));

        $this->putJson('/api/admin/settings/covers', ['cleanup_enabled' => false])->assertOk();
    }

    public function test_updating_loglevel_is_logged(): void
    {
        $admin = $this->actingAsAdmin();

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Settings updated', Mockery::on(function ($context) use ($admin) {
            return $context['actor_id'] === $admin->id && $context['category'] === 'loglevel' && $context['changes']['loglevel'] === 'DEBUG';
        }));

        $this->putJson('/api/admin/settings/loglevel', ['loglevel' => 'DEBUG'])->assertOk();
    }

    public function test_updating_timezone_is_logged(): void
    {
        $admin = $this->actingAsAdmin();

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Settings updated', Mockery::on(function ($context) use ($admin) {
            return $context['actor_id'] === $admin->id && $context['category'] === 'timezone' && $context['changes']['timezone'] === 'Europe/Berlin';
        }));

        $this->putJson('/api/admin/settings/timezone', ['timezone' => 'Europe/Berlin'])->assertOk();
    }

    public function test_updating_locale_is_logged(): void
    {
        $admin = $this->actingAsAdmin();

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Settings updated', Mockery::on(function ($context) use ($admin) {
            return $context['actor_id'] === $admin->id && $context['category'] === 'locale' && $context['changes']['default_language'] === 'de';
        }));

        $this->putJson('/api/admin/settings/locale', ['default_language' => 'de'])->assertOk();
    }
}
