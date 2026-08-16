<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRegistry;
use App\Models\MetadataPlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #44: GET /admin/metadata/plugins now attaches a `version`
 * attribute (declared by the matching provider class, not stored in the
 * database — same "attach live per request" shape as `config_fields`,
 * GitHub issue #29) to every plugin row, so PluginsPage.tsx can show it.
 */
class MetadataPluginVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_exposes_the_declared_version_per_provider_key(): void
    {
        $versions = app(MetadataProviderRegistry::class)->versionsByProviderKey();

        $this->assertSame('v1.0', $versions->get('book.open_library'));
        $this->assertSame('v1.0', $versions->get('cd.musicbrainz'));
        $this->assertSame('v1.0', $versions->get('cd.discogs'));
        $this->assertSame('v1.0', $versions->get('dvd_bluray.upcmdb'));
        $this->assertSame('v1.0', $versions->get('book.hardcover'));
        $this->assertSame('v1.0', $versions->get('book.google_books'));
    }

    public function test_plugins_endpoint_attaches_a_version_to_each_row(): void
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        app(MetadataProviderRegistry::class)->syncToDatabase();

        $response = $this->actingAs($admin)->getJson('/api/admin/metadata/plugins');

        $response->assertOk();
        $discogs = collect($response->json())->firstWhere('provider_key', 'cd.discogs');

        $this->assertSame('v1.0', $discogs['version']);
    }

    public function test_a_provider_with_no_matching_metadata_plugins_row_still_gets_a_version(): void
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        MetadataPlugin::query()->create([
            'provider_key' => 'book.open_library',
            'name' => 'Open Library',
            'media_type' => 'book',
            'enabled' => true,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/metadata/plugins');

        $response->assertOk();
        $openLibrary = collect($response->json())->firstWhere('provider_key', 'book.open_library');
        $this->assertSame('v1.0', $openLibrary['version']);
    }

    /** No registered provider class matches this row (e.g. a plugin removed from the codebase but not yet cleaned out of the table) — version is simply absent, not an error. */
    public function test_an_unregistered_provider_key_gets_no_version(): void
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
        $this->assertNull($unknown['version']);
    }
}
