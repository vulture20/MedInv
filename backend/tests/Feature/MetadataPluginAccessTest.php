<?php

namespace Tests\Feature;

use App\Models\MetadataPlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #37: GET /metadata/plugins used to sit outside the
 * level:admin route group (only auth:sanctum/active), so any logged-in
 * account — guest included — could read every MetadataPlugin row's `config`
 * column as-is, which is where a provider's admin-configured API key lives
 * (e.g. UpcMdbProvider). Per this project's own docs, metadata_plugins
 * config is "exactly as sensitive as a password hash" and should never
 * leave the backend outside an admin context. The route now lives at
 * GET /admin/metadata/plugins, behind level:admin.
 */
class MetadataPluginAccessTest extends TestCase
{
    use RefreshDatabase;

    private function pluginWithSecretKey(): MetadataPlugin
    {
        return MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.upcmdb',
            'name' => 'UPCitemdb',
            'media_type' => 'dvd_bluray',
            'enabled' => true,
            'config' => ['api_key' => 'TOTALLY-SECRET-KEY-123'],
            'priority' => 1,
        ]);
    }

    public function test_a_guest_cannot_read_the_plugin_list_at_all(): void
    {
        $this->pluginWithSecretKey();
        $guest = User::factory()->create(['level' => 'guest', 'is_active' => true]);

        $response = $this->actingAs($guest)->getJson('/api/admin/metadata/plugins');

        $response->assertForbidden();
    }

    public function test_an_ordinary_user_cannot_read_the_plugin_list_either(): void
    {
        $this->pluginWithSecretKey();
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);

        $response = $this->actingAs($user)->getJson('/api/admin/metadata/plugins');

        $response->assertForbidden();
    }

    public function test_the_old_unauthenticated_admin_route_no_longer_exists(): void
    {
        $this->pluginWithSecretKey();
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);

        $response = $this->actingAs($user)->getJson('/api/metadata/plugins');

        $response->assertNotFound();
    }

    public function test_an_admin_can_read_the_plugin_list_including_its_config(): void
    {
        $this->pluginWithSecretKey();
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($admin)->getJson('/api/admin/metadata/plugins');

        $response->assertOk();
        $response->assertJsonPath('0.config.api_key', 'TOTALLY-SECRET-KEY-123');
    }
}
