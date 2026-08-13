<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the admin-configurable default language (briefing 11.4): the
 * language a visitor's browser falls back to when it declares neither
 * German nor English, read publicly via GET /locale (frontend/src/i18n/
 * index.ts's applyAdminDefaultLanguage()) and set by an admin via
 * AdminSettingsController::updateLocale().
 */
class LocaleSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        // is_active explicitly true: see UserInvitationTest's identical
        // helper for why — Model::create() doesn't refresh DB-column
        // defaults into the in-memory instance actingAs() reuses.
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_the_public_locale_endpoint_defaults_to_english_without_authentication(): void
    {
        $response = $this->getJson('/api/locale');

        $response->assertOk()->assertJson(['default_language' => 'en']);
    }

    public function test_the_public_locale_endpoint_reflects_an_admin_configured_default(): void
    {
        SystemSetting::set('locale.default_language', 'de');

        $response = $this->getJson('/api/locale');

        $response->assertOk()->assertJson(['default_language' => 'de']);
    }

    public function test_an_admin_can_set_the_default_language(): void
    {
        $this->actingAsAdmin();

        $response = $this->putJson('/api/admin/settings/locale', ['default_language' => 'de']);

        $response->assertOk()->assertJson(['default_language' => 'de']);
        $this->assertSame('de', SystemSetting::get('locale.default_language'));
    }

    public function test_setting_a_language_other_than_de_or_en_is_rejected(): void
    {
        $this->actingAsAdmin();

        $response = $this->putJson('/api/admin/settings/locale', ['default_language' => 'fr']);

        $response->assertStatus(422);
    }

    public function test_a_non_admin_cannot_set_the_default_language(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        $response = $this->putJson('/api/admin/settings/locale', ['default_language' => 'de']);

        $response->assertStatus(403);
        $this->assertSame('en', SystemSetting::get('locale.default_language', 'en'));
    }

    public function test_the_admin_settings_index_includes_the_locale_default(): void
    {
        $this->actingAsAdmin();
        SystemSetting::set('locale.default_language', 'de');

        $response = $this->getJson('/api/admin/settings');

        $response->assertOk()->assertJsonPath('locale.default_language', 'de');
    }
}
