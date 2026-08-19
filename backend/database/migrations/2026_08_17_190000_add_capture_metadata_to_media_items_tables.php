<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub issue #74's two "größerer Aufwand" ideas — both explicitly flagged
 * in the issue as needing a new database field, not just a new query over
 * existing data:
 *
 * - `capture_method` ('scan'|'manual'): which of the two capture entry
 *   points actually created the item. 'scan' covers every route through the
 *   automated EAN + metadata-provider lookup pipeline (briefing 7.2) —
 *   hardware scanner, camera scan, or a text-file import all feed the same
 *   BulkImportService::resolveOne() flow and are confirmed via the same
 *   MetadataController::import() endpoint, regardless of which of those
 *   three actually produced the EAN. 'manual' is the standalone "Add
 *   manually" form (CreateMediaItemDialog.tsx, MediaItemController::store())
 *   — including a capture-flow `no_match` dead end, since even though the
 *   EAN there came from a scan, the item itself was hand-typed field by
 *   field with no provider match at all. Nullable rather than defaulted,
 *   so an item that existed before this migration honestly reports
 *   "unknown" instead of a guessed value.
 * - `metadata_provider`: comma-separated provider key(s) whose data ended
 *   up in the confirmed record (MetadataMergeReview.tsx tracks this per
 *   field already, via MergedField.options[].provider_keys) — a
 *   comma-separated string, the same convention MediaDvdBluray.languages
 *   already uses for a multi-value column (see StatisticsService::
 *   multiValueDistribution()). Null for a manual entry, or a metadata
 *   refresh where the user picked no provider-sourced field at all.
 * - `captured_by_user_id`: who was logged in when the item was created —
 *   the data captured_by/created_by field this app never had (issue #74's
 *   second harder idea, "Aktivität je Benutzer"). nullOnDelete(), not
 *   restrictOnDelete() like libraries.owner_id (see
 *   2026_08_13_210000_restrict_delete_on_libraries_owner.php) or
 *   cascadeOnDelete(): this is pure attribution, not ownership — deleting a
 *   user must neither be blocked by, nor take down, media items they merely
 *   captured on behalf of a library they didn't necessarily own.
 *
 * Type-agnostic, so all three media tables get the same three columns —
 * same pattern as 2026_08_16_140000_add_currency_to_media_items_tables.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['media_books', 'media_cds', 'media_dvd_blurays'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('capture_method', 16)->nullable()->after('ean');
                $table->string('metadata_provider')->nullable()->after('capture_method');
                $table->foreignId('captured_by_user_id')->nullable()->after('metadata_provider')
                    ->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['media_books', 'media_cds', 'media_dvd_blurays'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropForeign(['captured_by_user_id']);
                $table->dropColumn(['capture_method', 'metadata_provider', 'captured_by_user_id']);
            });
        }
    }
};
