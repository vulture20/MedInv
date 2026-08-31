<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub issue #208: whether an item has known duplicate copies, and how
 * many — a deliberate extension beyond briefing 6.1-6.3's fixed
 * per-media-type attribute set, the same kind `location` (GitHub issue
 * #96) or `currency` (GitHub issue #58) already are, see those migrations
 * for the same reasoning.
 *
 * `has_duplicates` is `NOT NULL` with a DB-level default of `false` — unlike
 * `duplicate_count`, there's no meaningful "unknown" state for a plain yes/no
 * flag, so omitting it from an insert/update should just mean "no", the same
 * way every other boolean flag in this app already behaves.
 *
 * `duplicate_count` is nullable with no default, deliberately NOT mirroring
 * `disc_count`'s `NOT NULL DEFAULT 1` pattern (GitHub issue #155/#136):
 * "no count recorded" and "confirmed zero duplicates" are genuinely
 * different facts here, so coercing a blank count to some default number
 * would misrepresent data nobody actually entered. Frontend and backend
 * both keep the two fields in agreement (count cleared whenever the flag is
 * unset) — see `mediaItemFields.ts` and `MediaItemController::rulesFor()`.
 *
 * Type-agnostic (any media type can have duplicate copies), so all three
 * media tables get the same two columns, same pattern as every other
 * cross-media-type extension so far.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['media_books', 'media_cds', 'media_dvd_blurays'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->boolean('has_duplicates')->default(false);
                $table->unsignedInteger('duplicate_count')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['media_books', 'media_cds', 'media_dvd_blurays'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['has_duplicates', 'duplicate_count']);
            });
        }
    }
};
