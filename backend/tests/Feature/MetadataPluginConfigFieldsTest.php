<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRegistry;
use App\Models\MetadataPlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #29: GET /admin/metadata/plugins now attaches a
 * `config_fields` descriptor (declared by the matching provider class, not
 * stored in the database) to every plugin row, so PluginsPage.tsx can
 * render a real settings form per plugin instead of a raw JSON textarea.
 * Uses an admin-level actor throughout — the endpoint moved into the
 * level:admin route group as part of GitHub issue #37 (it used to be
 * reachable by any logged-in account, guest included, leaking stored
 * provider API keys via `config`).
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
            ['key' => 'api_key', 'type' => 'password', 'required' => true, 'default' => null, 'options' => null],
        ], $fields->get('dvd_bluray.upcmdb'));
        $this->assertSame([
            ['key' => 'api_key', 'type' => 'password', 'required' => true, 'default' => null, 'options' => null],
        ], $fields->get('book.hardcover'));
    }

    /** GitHub issue #59: the Claude providers' prompt field is 'textarea', not 'text'/'password', and carries a non-null suggested default. */
    public function test_the_claude_providers_expose_an_api_key_and_a_default_valued_prompt_textarea(): void
    {
        $fields = app(MetadataProviderRegistry::class)->configFieldsByProviderKey();

        foreach (['book.claude', 'cd.claude', 'dvd_bluray.claude'] as $providerKey) {
            $fieldsForProvider = collect($fields->get($providerKey));
            $apiKeyField = $fieldsForProvider->firstWhere('key', 'api_key');
            $promptField = $fieldsForProvider->firstWhere('key', 'prompt');

            $this->assertSame(['key' => 'api_key', 'type' => 'password', 'required' => true, 'default' => null, 'options' => null], $apiKeyField);
            $this->assertSame('textarea', $promptField['type']);
            $this->assertFalse($promptField['required']);
            $this->assertNotEmpty($promptField['default']);
        }
    }

    /** GitHub issue #210: the first provider anywhere in this app to declare a 'select' field — see MetadataProviderConfigField's own docblock for why $options travels as raw values, not {value,label} pairs. */
    public function test_the_amazon_providers_expose_a_marketplace_select_with_two_options(): void
    {
        $fields = app(MetadataProviderRegistry::class)->configFieldsByProviderKey();

        foreach (['book.amazon', 'cd.amazon', 'dvd_bluray.amazon'] as $providerKey) {
            $this->assertSame([
                ['key' => 'marketplace', 'type' => 'select', 'required' => false, 'default' => null, 'options' => ['amazon.com', 'amazon.de']],
            ], $fields->get($providerKey));
        }
    }

    public function test_plugins_endpoint_attaches_config_fields_to_each_row(): void
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        app(MetadataProviderRegistry::class)->syncToDatabase();

        $response = $this->actingAs($admin)->getJson('/api/admin/metadata/plugins');

        $response->assertOk();
        $upcmdb = collect($response->json())->firstWhere('provider_key', 'dvd_bluray.upcmdb');
        $openLibrary = collect($response->json())->firstWhere('provider_key', 'book.open_library');

        $this->assertSame([
            ['key' => 'api_key', 'type' => 'password', 'required' => true, 'default' => null, 'options' => null],
        ], $upcmdb['config_fields']);
        $this->assertSame([], $openLibrary['config_fields']);
    }

    public function test_a_provider_with_no_matching_metadata_plugins_row_still_gets_empty_config_fields(): void
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
        $this->assertSame([], $unknown['config_fields']);
    }
}
