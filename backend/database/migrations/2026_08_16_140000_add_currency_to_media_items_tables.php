<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub issue #58 (follow-up): `price` (briefing 6.1-6.3) has always been
 * a bare `decimal` with no currency of its own — fine as long as every
 * price in a library came from the same implicit currency, but issue #58's
 * investigation found a genuine source of mixed currencies once a metadata
 * provider's own price (e.g. Google Books' `saleInfo.listPrice`, which
 * carries an explicit `currencyCode` that isn't pinned to any one
 * deployment-chosen value) gets stored next to a manually entered price in
 * a different currency. This deliberately extends the fixed attribute set
 * per explicit user request, the same way #48 added `tracks`/
 * `runtime_seconds` to CDs.
 *
 * A plain nullable `string(3)` (an ISO 4217 code like "USD"/"EUR"), not an
 * enum/foreign key to a currencies table — this only *records* which
 * currency a given price is in for the admin's own reference, it doesn't
 * drive any conversion/aggregation logic (StatisticsService's "Gesamtwert
 * des Bestands" still sums `price` as a bare number across every item in a
 * library regardless of `currency`, unchanged by this migration — a
 * currency-aware total is a separate feature, not attempted here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_books', function (Blueprint $table) {
            $table->string('currency', 3)->nullable()->after('price');
        });
        Schema::table('media_cds', function (Blueprint $table) {
            $table->string('currency', 3)->nullable()->after('price');
        });
        Schema::table('media_dvd_blurays', function (Blueprint $table) {
            $table->string('currency', 3)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('media_books', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
        Schema::table('media_cds', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
        Schema::table('media_dvd_blurays', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
