<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub issue #140: adds `genre` and `subtitles` to `media_dvd_blurays`,
 * the same deliberate extension-beyond-briefing-6.3 pattern `currency`/
 * `capture_method`/`location` already established (see
 * MediaDvdBluray::class's own docblock) — `genre` mirrors the column
 * `MediaBook` already has (now shared between the two media types, not
 * duplicated with a different meaning); `subtitles` (e.g. "Deutsch,
 * Englisch") is new to this app, the same free-text comma-separated shape
 * `languages` already uses on this same table for spoken-language tracks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_dvd_blurays', function (Blueprint $table) {
            $table->string('genre')->nullable()->after('director');
            $table->string('subtitles')->nullable()->after('languages');
        });
    }

    public function down(): void
    {
        Schema::table('media_dvd_blurays', function (Blueprint $table) {
            $table->dropColumn(['genre', 'subtitles']);
        });
    }
};
