<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub issue #151: an item captured without a known EAN gets a
 * generated `NoEAN-{13 random digits}` placeholder (19 characters) —
 * MediaItemService::generateNoEanPlaceholder()'s own docblock has the
 * full story. The `ean` column was `varchar(13)` (exactly the length of
 * a real EAN-13/ISBN-13, the longest real code this app ever scans),
 * too short to hold that placeholder, so it's widened to 20 here (19
 * needed, one character of headroom) across all three media tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['media_books', 'media_cds', 'media_dvd_blurays'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('ean', 20)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['media_books', 'media_cds', 'media_dvd_blurays'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('ean', 13)->change();
            });
        }
    }
};
