<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRegistry;
use App\Models\MetadataPlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #55: GET /admin/metadata/plugins now attaches a
 * `source_type` attribute ('api'|'scraping', declared by the matching
 * provider class, not stored in the database — same "attach live per
 * request" shape as `config_fields`/`version`, GitHub issues #29/#44) to
 * every plugin row, so PluginsPage.tsx can show the difference explicitly
 * instead of it only being documented in source/GitHub issues.
 */
class MetadataPluginSourceTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_exposes_the_declared_source_type_per_provider_key(): void
    {
        $sourceTypes = app(MetadataProviderRegistry::class)->sourceTypesByProviderKey();

        $this->assertSame('api', $sourceTypes->get('book.open_library'));
        $this->assertSame('api', $sourceTypes->get('book.google_books'));
        $this->assertSame('api', $sourceTypes->get('book.hardcover'));
        $this->assertSame('api', $sourceTypes->get('cd.musicbrainz'));
        $this->assertSame('api', $sourceTypes->get('cd.discogs'));
        $this->assertSame('api', $sourceTypes->get('dvd_bluray.upcmdb'));
        // GitHub issue #50, GitHub issue #129, GitHub issue #130: the three
        // Amazon scrapers, the three Thalia ones, and the two JPC ones
        // (cd/dvd_bluray only) are the only 'scraping' providers.
        $this->assertSame('scraping', $sourceTypes->get('book.amazon'));
        $this->assertSame('scraping', $sourceTypes->get('cd.amazon'));
        $this->assertSame('scraping', $sourceTypes->get('dvd_bluray.amazon'));
        $this->assertSame('scraping', $sourceTypes->get('book.thalia'));
        $this->assertSame('scraping', $sourceTypes->get('cd.thalia'));
        $this->assertSame('scraping', $sourceTypes->get('dvd_bluray.thalia'));
        $this->assertSame('scraping', $sourceTypes->get('cd.jpc'));
        $this->assertSame('scraping', $sourceTypes->get('dvd_bluray.jpc'));
        // GitHub issue #59, GitHub issue #65, GitHub issue #66: the three
        // Claude providers, the three OpenAI-backed ones, and the three
        // Gemini-backed ones are the only 'llm' providers.
        $this->assertSame('llm', $sourceTypes->get('book.claude'));
        $this->assertSame('llm', $sourceTypes->get('cd.claude'));
        $this->assertSame('llm', $sourceTypes->get('dvd_bluray.claude'));
        $this->assertSame('llm', $sourceTypes->get('book.openai'));
        $this->assertSame('llm', $sourceTypes->get('cd.openai'));
        $this->assertSame('llm', $sourceTypes->get('dvd_bluray.openai'));
        $this->assertSame('llm', $sourceTypes->get('book.gemini'));
        $this->assertSame('llm', $sourceTypes->get('cd.gemini'));
        $this->assertSame('llm', $sourceTypes->get('dvd_bluray.gemini'));
    }

    public function test_plugins_endpoint_attaches_a_source_type_to_each_row(): void
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        app(MetadataProviderRegistry::class)->syncToDatabase();

        $response = $this->actingAs($admin)->getJson('/api/admin/metadata/plugins');

        $response->assertOk();
        $discogs = collect($response->json())->firstWhere('provider_key', 'cd.discogs');
        $amazon = collect($response->json())->firstWhere('provider_key', 'book.amazon');

        $this->assertSame('api', $discogs['source_type']);
        $this->assertSame('scraping', $amazon['source_type']);
    }

    /** No registered provider class matches this row — source_type is simply absent, not an error, same as version(). */
    public function test_an_unregistered_provider_key_gets_no_source_type(): void
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
        $this->assertNull($unknown['source_type']);
    }
}
