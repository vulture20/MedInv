<?php

use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\MediaDvdBluray;
use Illuminate\Database\Migrations\Migration;

/**
 * GitHub issue #212 (user-reported against a real deployment): before
 * AmazonScraping::normalizeCurrency() existed, a scrape whose JSON price
 * blob returned the literal glyph "€" for `currencySymbol` (rather than
 * the ISO 4217 code "EUR" this trait had assumed it always was — see that
 * method's own docblock) got written straight into `currency` unnormalized,
 * since MediaItemController::rulesFor()'s validation rule for that column
 * (`['nullable', 'string', 'max:3']`) trivially accepts a one-character
 * glyph. The code fix alone only prevents *future* scrapes from repeating
 * this — any item already captured/refreshed with a bad glyph value stays
 * wrong in the database forever without this migration repairing it.
 *
 * Reuses the exact same glyph->ISO mapping AmazonScraping::
 * CURRENCY_SYMBOL_TO_ISO uses (duplicated here rather than referencing that
 * private trait constant, since a migration should stay meaningful and
 * self-contained independent of later application-code changes). Applied
 * across all three media tables and unconditionally — not scoped to
 * `metadata_provider LIKE '%.amazon'` — since a bare currency symbol is
 * never a valid value regardless of how it got there, and narrowing the
 * scope would only risk missing a row this is meant to fix.
 */
return new class extends Migration
{
    private const CURRENCY_SYMBOL_TO_ISO = [
        '€' => 'EUR',
        '$' => 'USD',
        '£' => 'GBP',
        '¥' => 'JPY',
    ];

    public function up(): void
    {
        foreach ([MediaBook::class, MediaCd::class, MediaDvdBluray::class] as $modelClass) {
            foreach (self::CURRENCY_SYMBOL_TO_ISO as $symbol => $iso) {
                $modelClass::query()->where('currency', $symbol)->update(['currency' => $iso]);
            }
        }
    }

    public function down(): void
    {
        // Deliberately a no-op — same reasoning as
        // 2026_08_20_193000_remove_thalia_metadata_plugins.php's own
        // down(): reversing this would require knowing which rows were
        // originally a bad glyph versus already a legitimately correct
        // ISO code (e.g. a real EUR price from a working scrape), which is
        // no longer distinguishable once both look identical ("EUR").
        // There's nothing meaningful to roll back to.
    }
};
