<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The daily orphaned-cover-file cleanup (CoverCleanupService, routes/
 * console.php's `medinv-cover-cleanup` schedule) is admin-toggleable via
 * `covers.cleanup_enabled` (AdminSettingsController::updateCoverCleanup()),
 * default enabled. See CleanupOrphanedCoversCommandTest for the schedule
 * itself actually respecting the setting.
 */
class CoverCleanupSettingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_defaults_to_enabled(): void
    {
        $this->assertTrue(SystemSetting::get('covers.cleanup_enabled', true));
        $this->assertTrue(SystemSetting::defaults()['covers.cleanup_enabled']);
    }

    public function test_the_admin_settings_index_includes_the_current_value(): void
    {
        $this->actingAsAdmin();
        SystemSetting::set('covers.cleanup_enabled', false);

        $response = $this->getJson('/api/admin/settings');

        $response->assertOk()->assertJsonPath('covers.cleanup_enabled', false);
    }

    public function test_an_admin_can_disable_it(): void
    {
        $this->actingAsAdmin();

        $response = $this->putJson('/api/admin/settings/covers', ['cleanup_enabled' => false]);

        $response->assertOk()->assertJson(['cleanup_enabled' => false]);
        $this->assertFalse(SystemSetting::get('covers.cleanup_enabled'));
    }

    public function test_an_admin_can_re_enable_it(): void
    {
        $this->actingAsAdmin();
        SystemSetting::set('covers.cleanup_enabled', false);

        $response = $this->putJson('/api/admin/settings/covers', ['cleanup_enabled' => true]);

        $response->assertOk()->assertJson(['cleanup_enabled' => true]);
        $this->assertTrue(SystemSetting::get('covers.cleanup_enabled'));
    }

    public function test_a_non_admin_cannot_change_it(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        $response = $this->putJson('/api/admin/settings/covers', ['cleanup_enabled' => false]);

        $response->assertStatus(403);
        $this->assertTrue(SystemSetting::get('covers.cleanup_enabled', true));
    }

    public function test_a_non_boolean_value_is_rejected(): void
    {
        $this->actingAsAdmin();

        $response = $this->putJson('/api/admin/settings/covers', ['cleanup_enabled' => 'not-a-boolean']);

        $response->assertStatus(422);
    }
}
