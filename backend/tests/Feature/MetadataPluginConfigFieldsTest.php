<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRegistry;
use App\Models\MetadataPlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #29: GET /metadata/plugins now attaches a `config_fields`
 * descriptor (declared by the matching provider class, not stored in the
 * database) to every plugin row, so PluginsPage.tsx can render a real
 * settings form per plugin instead of a raw JSON textarea.
 */
class MetadataPluginConfigFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_exposes_the_declared_config_fields_per_provider_key(): void
    {
        $fields = app(MetadataProviderRegistry::class)->configFieldsByProviderKey();

        $this->assertSame([], $fields->get('book.open_library'));
        $this->assertSame([], $fields->get('cd.musicbrainz'));
        $this->assertSame([
            ['key' => 'api_key', 'type' => 'password', 'required' => true],
        ], $fields->get('dvd_bluray.upcmdb'));
    }

    public function test_plugins_endpoint_attaches_config_fields_to_each_row(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        app(MetadataProviderRegistry::class)->syncToDatabase();

        $response = $this->actingAs($user)->getJson('/api/metadata/plugins');

        $response->assertOk();
        $upcmdb = collect($response->json())->firstWhere('provider_key', 'dvd_bluray.upcmdb');
        $openLibrary = collect($response->json())->firstWhere('provider_key', 'book.open_library');

        $this->assertSame([
            ['key' => 'api_key', 'type' => 'password', 'required' => true],
        ], $upcmdb['config_fields']);
        $this->assertSame([], $openLibrary['config_fields']);
    }

    public function test_a_provider_with_no_matching_metadata_plugins_row_still_gets_empty_config_fields(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        MetadataPlugin::query()->create([
            'provider_key' => 'book.some_future_provider',
            'name' => 'Future Provider',
            'media_type' => 'book',
            'enabled' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/metadata/plugins');

        $response->assertOk();
        $unknown = collect($response->json())->firstWhere('provider_key', 'book.some_future_provider');
        $this->assertSame([], $unknown['config_fields']);
    }
}
