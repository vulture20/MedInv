<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRegistry;
use App\Models\MetadataPlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #158: GET /admin/metadata/plugins now attaches a
 * `supports_code_lookup` attribute (declared by the matching provider
 * class, not stored in the database — same "attach live per request"
 * shape as `config_fields`/`version`/`source_type`, GitHub issues
 * #29/#44/#55) to every plugin row, so PluginsPage.tsx can show, per
 * plugin, whether it can ever meaningfully contribute a result for a
 * scanned/entered EAN at all — as opposed to only ever surfacing through
 * the free-text search path. Every provider implemented so far supports
 * it; GitHub issue #157 (a proposed TMDB provider) is expected to be the
 * first real `false`, since the movie database itself has no barcode
 * lookup capability.
 */
class MetadataPluginEanSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_exposes_true_for_every_currently_registered_provider(): void
    {
        $eanSupport = app(MetadataProviderRegistry::class)->eanSupportByProviderKey();

        foreach (MetadataProviderRegistry::defaultProviders() as $class) {
            $key = app($class)->key();
            $this->assertTrue($eanSupport->get($key), "Expected {$key} to support code lookup.");
        }
    }

    public function test_plugins_endpoint_attaches_supports_code_lookup_to_each_row(): void
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        app(MetadataProviderRegistry::class)->syncToDatabase();

        $response = $this->actingAs($admin)->getJson('/api/admin/metadata/plugins');

        $response->assertOk();
        $discogs = collect($response->json())->firstWhere('provider_key', 'cd.discogs');

        $this->assertTrue($discogs['supports_code_lookup']);
    }

    /** No registered provider class matches this row — supports_code_lookup is simply absent, not an error, same as version()/source_type(). */
    public function test_an_unregistered_provider_key_gets_no_ean_support_value(): void
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        MetadataPlugin::query()->create([
            'provider_key' => 'book.some_future_provider',
            'name' => 'Future Provider',
            'media_type' => 'book',
            'enabled' => true,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/metadata/plugins');

        $response->assertOk();
        $unknown = collect($response->json())->firstWhere('provider_key', 'book.some_future_provider');
        $this->assertNull($unknown['supports_code_lookup']);
    }
}
