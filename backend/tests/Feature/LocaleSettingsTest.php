<?php

namespace Tests\Feature;

use App\Models\LanguagePack;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the admin-configurable default language (briefing 11.4): the
 * language a visitor's browser falls back to when it matches none of the
 * installed languages (bundled or runtime pack), read publicly via
 * GET /locale (frontend/src/i18n/index.ts's applyBrowserOrDefaultLanguage())
 * and set by an admin via AdminSettingsController::updateLocale().
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

    public function test_setting_a_language_with_no_matching_language_pack_is_rejected(): void
    {
        $this->actingAsAdmin();

        $response = $this->putJson('/api/admin/settings/locale', ['default_language' => 'fr']);

        $response->assertStatus(422);
    }

    /**
     * GitHub issues #12/#15 follow-up: the default-language selector was
     * originally hardcoded to de/en only — an admin-added or bundled
     * (BundledLanguagePackRegistry) language pack is just as valid a
     * default, as long as it currently has a language_packs row.
     */
    public function test_an_admin_can_set_the_default_language_to_an_installed_language_pack(): void
    {
        $this->actingAsAdmin();
        LanguagePack::query()->create(['code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b']]);

        $response = $this->putJson('/api/admin/settings/locale', ['default_language' => 'fr']);

        $response->assertOk()->assertJson(['default_language' => 'fr']);
        $this->assertSame('fr', SystemSetting::get('locale.default_language'));
    }

    public function test_setting_the_default_language_to_a_since_deleted_language_pack_is_rejected(): void
    {
        $this->actingAsAdmin();
        $pack = LanguagePack::query()->create(['code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b']]);
        $pack->delete();

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
