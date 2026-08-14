<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRegistry;
use App\Domain\Metadata\Providers\DvdBluray\UpcMdbProvider;
use App\Models\LanguagePack;
use App\Models\MetadataPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Found while correcting UpcMdbProvider (previously implemented under the
 * wrong service name): nothing ever called
 * MetadataProviderRegistry::syncToDatabase(), so a fresh install had zero
 * metadata_plugins rows — MetadataImportService only ever queries
 * *enabled* rows (enabledProvidersFor()), so every capture/search silently
 * returned "no_match" regardless of which providers were implemented (see
 * GitHub issue #17). DatabaseSeeder now calls syncToDatabase() itself.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_creates_a_row_for_every_default_provider(): void
    {
        $this->seed();

        $table = (new MetadataPlugin)->getTable();
        $this->assertDatabaseHas($table, ['provider_key' => 'book.open_library', 'enabled' => true]);
        $this->assertDatabaseHas($table, ['provider_key' => 'book.google_books', 'enabled' => true]);
        $this->assertDatabaseHas($table, ['provider_key' => 'book.hardcover', 'enabled' => true]);
        $this->assertDatabaseHas($table, ['provider_key' => 'cd.musicbrainz', 'enabled' => true]);
        $this->assertDatabaseHas($table, ['provider_key' => 'cd.discogs', 'enabled' => true]);
        $this->assertDatabaseHas($table, ['provider_key' => 'dvd_bluray.upcmdb', 'enabled' => true]);
    }

    public function test_seeding_twice_does_not_duplicate_metadata_plugin_rows(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(6, MetadataPlugin::query()->count());
    }

    public function test_a_freshly_seeded_install_actually_has_an_enabled_dvd_bluray_provider(): void
    {
        $this->seed();

        $providers = app(MetadataProviderRegistry::class)->enabledProvidersFor('dvd_bluray');

        $this->assertTrue($providers->contains(fn ($p) => $p instanceof UpcMdbProvider));
    }

    /**
     * Uses the real languagepacks/ directory (unlike BundledLanguagePackTest's
     * fixture-based coverage of BundledLanguagePackRegistry itself) — this is
     * specifically an integration check that DatabaseSeeder's wiring and
     * config('medinv.languagepacks_path')'s default actually reach the real,
     * repo-shipped files, so a fresh install really does have them
     * pre-installed from the start as intended.
     */
    public function test_a_freshly_seeded_install_has_the_bundled_language_packs(): void
    {
        $this->seed();

        foreach (['fr', 'es', 'ja', 'zh', 'it', 'pt', 'nl', 'pl'] as $code) {
            $this->assertDatabaseHas((new LanguagePack)->getTable(), ['code' => $code]);
        }
    }
}
