<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Zeitlicher Zuwachs des Bestands" (briefing 14., GitHub issue #30) — one
 * row per library per calendar day, written by
 * StatisticsService::snapshotAll() via the daily scheduler entry in
 * routes/console.php (SnapshotLibraryValuesCommand). Pure reporting data
 * derived from the library's own media items at the time of the snapshot,
 * so it cascades away with the library rather than blocking its deletion
 * the way libraries.owner_id does (2026_08_13_210000_restrict_delete_on_
 * libraries_owner.php) — nothing else references a snapshot row, unlike an
 * owner account that other users' shares still depend on.
 *
 * This only gives accurate history from whenever this feature first ran
 * onward. For the period before that, StatisticsService::valueHistoryFor()
 * falls back to an approximation derived from each item's created_at
 * timestamp — cheap (no schema change needed for pre-existing data) but
 * unable to reflect a later deletion or price edit, since created_at only
 * ever records the capture date, not the historical bestand at an
 * arbitrary point in the past. See that method's docblock for how the two
 * sources are combined.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_value_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained('libraries')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->unsignedInteger('item_count');
            $table->decimal('total_value', 14, 2);
            $table->timestamps();

            $table->unique(['library_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_value_snapshots');
    }
};
