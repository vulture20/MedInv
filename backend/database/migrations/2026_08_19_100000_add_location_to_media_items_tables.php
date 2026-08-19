<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub issue #96: a plain free-text "where is this physically stored"
 * field (e.g. "Regal 3, Fach 2") — the same kind of deliberate extension
 * beyond briefing 6.1-6.3's fixed per-media-type attribute set as `currency`
 * (GitHub issue #58) or `capture_method`/`metadata_provider`/
 * `captured_by_user_id` (GitHub issue #74), see those migrations for the
 * same reasoning. Not a foreign key to a separate locations table and not
 * validated against a fixed list — this only records whatever the user
 * types, the same "admin's own reference" stance `currency` already takes.
 *
 * Type-agnostic (a storage location makes just as much sense for a book as
 * a CD or a DVD/Blu-ray), so all three media tables get the same column,
 * same pattern as every other cross-media-type extension so far.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['media_books', 'media_cds', 'media_dvd_blurays'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('location')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['media_books', 'media_cds', 'media_dvd_blurays'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('location');
            });
        }
    }
};
