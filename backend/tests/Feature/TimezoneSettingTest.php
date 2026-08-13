<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GitHub issue #31: backup/export filenames used to always embed UTC
 * (via config('app.timezone'), fixed in config/app.php with no way to
 * configure it), regardless of where the admin/server actually is —
 * confusing when trying to match a filename to "today at 3am" on the
 * admin's own clock. `timezone` (SystemSetting::localNow()) fixes this
 * for filenames/text a human reads, without touching stored timestamps
 * or the scheduled-task due-check logic — see localNow()'s docblock.
 */
class TimezoneSettingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_defaults_to_utc(): void
    {
        $this->assertSame('UTC', SystemSetting::get('timezone', 'UTC'));
        $this->assertSame('UTC', SystemSetting::defaults()['timezone']);
    }

    public function test_local_now_uses_the_configured_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 15:30:00', 'UTC'));
        SystemSetting::set('timezone', 'Europe/Berlin');

        // Confirmed 2-hour CEST offset from UTC in August, matching the
        // exact discrepancy the original bug report described.
        $this->assertSame('2026-08-13 17:30:00', SystemSetting::localNow()->format('Y-m-d H:i:s'));
    }

    public function test_local_now_defaults_to_utc_when_unconfigured(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 15:30:00', 'UTC'));

        $this->assertSame('2026-08-13 15:30:00', SystemSetting::localNow()->format('Y-m-d H:i:s'));
    }

    public function test_local_now_does_not_change_the_global_default_timezone(): void
    {
        SystemSetting::set('timezone', 'Europe/Berlin');

        SystemSetting::localNow();

        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
    }

    public function test_the_admin_settings_index_includes_the_current_value(): void
    {
        $this->actingAsAdmin();
        SystemSetting::set('timezone', 'Europe/Berlin');

        $response = $this->getJson('/api/admin/settings');

        $response->assertOk()->assertJsonPath('timezone', 'Europe/Berlin');
    }

    public function test_an_admin_can_set_the_timezone(): void
    {
        $this->actingAsAdmin();

        $response = $this->putJson('/api/admin/settings/timezone', ['timezone' => 'America/New_York']);

        $response->assertOk()->assertJson(['timezone' => 'America/New_York']);
        $this->assertSame('America/New_York', SystemSetting::get('timezone'));
    }

    public function test_setting_an_unrecognized_timezone_is_rejected(): void
    {
        $this->actingAsAdmin();

        $response = $this->putJson('/api/admin/settings/timezone', ['timezone' => 'Not/A_Real_Zone']);

        $response->assertStatus(422);
        $this->assertSame('UTC', SystemSetting::get('timezone', 'UTC'));
    }

    public function test_a_non_admin_cannot_set_the_timezone(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        $response = $this->putJson('/api/admin/settings/timezone', ['timezone' => 'Europe/Berlin']);

        $response->assertStatus(403);
        $this->assertSame('UTC', SystemSetting::get('timezone', 'UTC'));
    }
}
