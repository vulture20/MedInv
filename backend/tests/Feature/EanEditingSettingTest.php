<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #202: whether admins may use GitHub issue #201's admin-only
 * EAN editor at all is itself admin-toggleable via `ean_editing.enabled`
 * (AdminSettingsController::updateEanEditing()), default enabled — see
 * CoverCleanupSettingTest for the near-identical precedent this mirrors.
 * MediaItemUpdateTest covers MediaItemController::update() enforcing this
 * setting server-side (a disabled admin gets exactly the pre-#201 "ean is
 * silently dropped" behavior).
 */
class EanEditingSettingTest extends TestCase
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
        $this->assertTrue(SystemSetting::get('ean_editing.enabled', true));
        $this->assertTrue(SystemSetting::defaults()['ean_editing.enabled']);
    }

    public function test_the_admin_settings_index_includes_the_current_value(): void
    {
        $this->actingAsAdmin();
        SystemSetting::set('ean_editing.enabled', false);

        $response = $this->getJson('/api/admin/settings');

        $response->assertOk()->assertJsonPath('ean_editing.enabled', false);
    }

    public function test_an_admin_can_disable_it(): void
    {
        $this->actingAsAdmin();

        $response = $this->putJson('/api/admin/settings/ean-editing', ['enabled' => false]);

        $response->assertOk()->assertJson(['enabled' => false]);
        $this->assertFalse(SystemSetting::get('ean_editing.enabled'));
    }

    public function test_an_admin_can_re_enable_it(): void
    {
        $this->actingAsAdmin();
        SystemSetting::set('ean_editing.enabled', false);

        $response = $this->putJson('/api/admin/settings/ean-editing', ['enabled' => true]);

        $response->assertOk()->assertJson(['enabled' => true]);
        $this->assertTrue(SystemSetting::get('ean_editing.enabled'));
    }

    public function test_a_non_admin_cannot_change_it(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        $response = $this->putJson('/api/admin/settings/ean-editing', ['enabled' => false]);

        $response->assertStatus(403);
        $this->assertTrue(SystemSetting::get('ean_editing.enabled', true));
    }

    public function test_a_non_boolean_value_is_rejected(): void
    {
        $this->actingAsAdmin();

        $response = $this->putJson('/api/admin/settings/ean-editing', ['enabled' => 'not-a-boolean']);

        $response->assertStatus(422);
    }

    /** @see AuthController::me() — mirrored there so already-open pages can gate the editor's UI without a dedicated request. */
    public function test_me_reports_the_current_value(): void
    {
        $this->actingAsAdmin();
        SystemSetting::set('ean_editing.enabled', false);

        $response = $this->getJson('/api/me');

        $response->assertOk()->assertJsonPath('ean_editing_enabled', false);
    }

    public function test_login_reports_the_current_value(): void
    {
        SystemSetting::set('ean_editing.enabled', false);
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true, 'password' => 'correct-password']);

        $response = $this->postJson(
            '/api/login',
            ['email' => $admin->email, 'password' => 'correct-password'],
            ['Origin' => 'http://localhost:5173']
        );

        $response->assertOk()->assertJsonPath('ean_editing_enabled', false);
    }

    /** Disabling the setting server-side is enforced even if an admin's own request still sends an 'ean' field. */
    public function test_disabling_the_setting_prevents_an_admin_from_changing_the_ean_via_update(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        SystemSetting::set('ean_editing.enabled', false);
        $this->actingAsAdmin();

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['ean' => '9780000000099']);

        $response->assertOk();
        $this->assertSame('9780000000001', $item->fresh()->ean);
    }
}
