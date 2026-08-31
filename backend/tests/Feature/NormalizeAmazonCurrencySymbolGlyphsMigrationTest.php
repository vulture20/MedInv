<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\MediaDvdBluray;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026_08_31_130000_normalize_amazon_currency_symbol_glyphs (GitHub issue
 * #212) — repairs media items whose `currency` column already holds a raw
 * glyph ("€", "$", ...) instead of an ISO 4217 code, written before
 * AmazonScraping::normalizeCurrency() existed. Same "load the migration
 * class directly" approach as EnableJpcMetadataPluginsMigrationTest, for the
 * same reason (this migration's `up()`/`down()` aren't reachable any other
 * way from a test).
 */
class NormalizeAmazonCurrencySymbolGlyphsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function createLibraryAndOwner(string $mediaType): Library
    {
        $owner = User::factory()->create();

        return Library::query()->create(['name' => 'Test', 'media_type' => $mediaType, 'owner_id' => $owner->id]);
    }

    public function test_up_converts_known_glyphs_to_iso_codes_across_all_three_media_tables(): void
    {
        $bookLibrary = $this->createLibraryAndOwner('book');
        $cdLibrary = $this->createLibraryAndOwner('cd');
        $dvdLibrary = $this->createLibraryAndOwner('dvd_bluray');

        $book = MediaBook::query()->create(['library_id' => $bookLibrary->id, 'title' => 'A Book', 'ean' => '9780000000001', 'currency' => '€']);
        $cd = MediaCd::query()->create(['library_id' => $cdLibrary->id, 'title' => 'A CD', 'ean' => '9780000000002', 'currency' => '$']);
        $dvd = MediaDvdBluray::query()->create(['library_id' => $dvdLibrary->id, 'title' => 'A Film', 'ean' => '9780000000003', 'currency' => '£']);
        // Already-valid values must stay untouched.
        $unaffected = MediaBook::query()->create(['library_id' => $bookLibrary->id, 'title' => 'Another Book', 'ean' => '9780000000004', 'currency' => 'USD']);

        /** @var Migration $migration */
        $migration = require base_path('database/migrations/2026_08_31_130000_normalize_amazon_currency_symbol_glyphs.php');
        $migration->up();

        $this->assertSame('EUR', $book->refresh()->currency);
        $this->assertSame('USD', $cd->refresh()->currency);
        $this->assertSame('GBP', $dvd->refresh()->currency);
        $this->assertSame('USD', $unaffected->refresh()->currency);
    }

    public function test_down_is_a_documented_no_op(): void
    {
        $bookLibrary = $this->createLibraryAndOwner('book');
        $book = MediaBook::query()->create(['library_id' => $bookLibrary->id, 'title' => 'A Book', 'ean' => '9780000000001', 'currency' => 'EUR']);

        /** @var Migration $migration */
        $migration = require base_path('database/migrations/2026_08_31_130000_normalize_amazon_currency_symbol_glyphs.php');
        $migration->down();

        $this->assertSame('EUR', $book->refresh()->currency);
    }
}
