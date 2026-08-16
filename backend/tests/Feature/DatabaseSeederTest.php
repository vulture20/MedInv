<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRegistry;
use App\Domain\Metadata\Providers\DvdBluray\UpcMdbProvider;
use App\Models\LanguagePack;
use App\Models\MetadataPlugin;
use App\Models\Template;
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

    /** GitHub issue #50: unlike every other default provider above, the three Beta Amazon scrapers must not be enabled just because the app was installed — an admin has to opt in. */
    public function test_seeding_creates_the_amazon_providers_disabled(): void
    {
        $this->seed();

        $table = (new MetadataPlugin)->getTable();
        $this->assertDatabaseHas($table, ['provider_key' => 'book.amazon', 'enabled' => false]);
        $this->assertDatabaseHas($table, ['provider_key' => 'cd.amazon', 'enabled' => false]);
        $this->assertDatabaseHas($table, ['provider_key' => 'dvd_bluray.amazon', 'enabled' => false]);
    }

    /** GitHub issue #59: same reasoning as the Amazon providers above (see MetadataProviderRegistry::DEFAULT_DISABLED_PROVIDER_KEYS's docblock) — an LLM-backed source costs real money per lookup and carries a hallucination risk, so it must not be enabled just because the app was installed. */
    public function test_seeding_creates_the_claude_providers_disabled(): void
    {
        $this->seed();

        $table = (new MetadataPlugin)->getTable();
        $this->assertDatabaseHas($table, ['provider_key' => 'book.claude', 'enabled' => false]);
        $this->assertDatabaseHas($table, ['provider_key' => 'cd.claude', 'enabled' => false]);
        $this->assertDatabaseHas($table, ['provider_key' => 'dvd_bluray.claude', 'enabled' => false]);
    }

    public function test_seeding_twice_does_not_duplicate_metadata_plugin_rows(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(count(MetadataProviderRegistry::defaultProviders()), MetadataPlugin::query()->count());
    }

    /**
     * Regression test: a real install's metadata_plugins.name for the
     * Amazon providers stayed "Amazon (Beta)" even after AmazonBookProvider
     * etc.'s name() was fixed to drop that suffix, because syncToDatabase()
     * used to be firstOrCreate()-only — it never touched a row that already
     * existed from before the code change. Simulates exactly that: a stale
     * row inserted with the pre-fix name, as if seeded by an older version
     * of this app, then re-synced (docker/entrypoint.sh's db:seed --force
     * runs on every boot) and asserted to have caught up with the current
     * provider name.
     */
    public function test_seeding_corrects_a_stale_provider_name_from_before_a_code_change(): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'book.amazon',
            'name' => 'Amazon (Beta)',
            'media_type' => 'book',
            'enabled' => false,
        ]);

        $this->seed();

        $table = (new MetadataPlugin)->getTable();
        $this->assertDatabaseHas($table, ['provider_key' => 'book.amazon', 'name' => 'Amazon']);
        $this->assertDatabaseMissing($table, ['provider_key' => 'book.amazon', 'name' => 'Amazon (Beta)']);
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

        foreach (['fr', 'es', 'ja', 'zh', 'it', 'pt', 'nl', 'pl', 'ru', 'uk', 'tr'] as $code) {
            $this->assertDatabaseHas((new LanguagePack)->getTable(), ['code' => $code]);
        }
    }

    /**
     * Same integration check as the language-pack one above, but for
     * templates/*.json (GitHub issue #11's bundled themes beyond
     * light/dark) — confirms config('medinv.templates_path')'s default
     * actually reaches the real, repo-shipped files on a fresh install.
     */
    public function test_a_freshly_seeded_install_has_the_bundled_templates(): void
    {
        $this->seed();

        foreach (['dracula', 'nord', 'solarized-light', 'sepia', 'gruvbox-dark', 'high-contrast'] as $code) {
            $this->assertDatabaseHas((new Template)->getTable(), ['code' => $code]);
        }
    }
}
