<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #16: admin-facing read/write of oidc.* system settings
 * (AdminSettingsController::index()['oidc']/updateOidc()). See
 * AdminSettingsLoggingTest for the audit-log/redaction coverage and
 * OidcAuthTest for the actual login-flow behavior these settings drive.
 */
class OidcSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_an_ordinary_user_cannot_read_admin_settings(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        $this->getJson('/api/admin/settings')->assertForbidden();
    }

    public function test_an_admin_can_read_oidc_settings_and_the_client_secret_is_never_included(): void
    {
        $this->actingAsAdmin();
        SystemSetting::set('oidc.enabled', true);
        SystemSetting::set('oidc.issuer', 'https://idp.example.test');
        SystemSetting::set('oidc.client_id', 'medinv');
        SystemSetting::set('oidc.client_secret', 'super-secret-value');
        SystemSetting::set('oidc.provider_name', 'Pocket ID');

        $response = $this->getJson('/api/admin/settings');

        $response->assertOk();
        $response->assertJsonPath('oidc.enabled', true);
        $response->assertJsonPath('oidc.issuer', 'https://idp.example.test');
        $response->assertJsonPath('oidc.provider_name', 'Pocket ID');
        $response->assertJsonMissingPath('oidc.client_secret');
        $this->assertStringNotContainsString('super-secret-value', $response->getContent());
    }

    public function test_an_admin_can_update_oidc_settings(): void
    {
        $this->actingAsAdmin();

        $response = $this->putJson('/api/admin/settings/oidc', [
            'enabled' => true, 'issuer' => 'https://idp.example.test', 'client_id' => 'medinv',
            'client_secret' => 'a-real-secret', 'provider_name' => 'Pocket ID',
            'auto_provision' => true, 'default_level' => 'guest',
        ]);

        $response->assertOk();
        $response->assertJson([
            'enabled' => true, 'issuer' => 'https://idp.example.test', 'client_id' => 'medinv',
            'provider_name' => 'Pocket ID', 'auto_provision' => true, 'default_level' => 'guest',
        ]);
        $this->assertSame('a-real-secret', SystemSetting::get('oidc.client_secret'));
    }

    public function test_leaving_the_client_secret_empty_preserves_the_previously_saved_one(): void
    {
        $this->actingAsAdmin();
        SystemSetting::set('oidc.client_secret', 'the-original-secret');

        $this->putJson('/api/admin/settings/oidc', [
            'enabled' => true, 'issuer' => 'https://idp.example.test', 'client_id' => 'medinv',
            'client_secret' => '', 'provider_name' => 'Pocket ID',
            'auto_provision' => false, 'default_level' => 'user',
        ])->assertOk();

        $this->assertSame('the-original-secret', SystemSetting::get('oidc.client_secret'));
    }

    public function test_default_level_rejects_admin(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/admin/settings/oidc', [
            'enabled' => false, 'auto_provision' => true, 'default_level' => 'admin',
        ])->assertStatus(422);
    }

    public function test_an_ordinary_user_cannot_update_oidc_settings(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        $this->putJson('/api/admin/settings/oidc', [
            'enabled' => true, 'auto_provision' => false, 'default_level' => 'user',
        ])->assertForbidden();
    }

    public function test_an_unauthenticated_request_cannot_update_oidc_settings(): void
    {
        $this->putJson('/api/admin/settings/oidc', [
            'enabled' => true, 'auto_provision' => false, 'default_level' => 'user',
        ])->assertUnauthorized();
    }
}
