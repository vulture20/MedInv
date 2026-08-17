<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Follow-up to issue #72: 2026_08_17_120000_add_pg_trgm_index_for_media_cd_tracks.php
 * (issue #57) built a GIN trigram index on `LOWER((tracks)::text)` — the
 * whole JSON blob as text. #72 replaced that coarse whole-blob matching
 * with a precise per-element `tracks[].title` match (see
 * SearchService::addPostgresJsonArrayFieldConditions()), which queries
 * `jsonb_array_elements()` output, an entirely different expression shape
 * the old index's expression no longer matches at all — Postgres never
 * uses it for the new query, so it's now dead weight: still maintained on
 * every INSERT/UPDATE of a CD (each of which JSON-encodes and re-scans the
 * whole tracks blob into the index), for zero remaining query benefit.
 *
 * Dropping it here rather than editing the original migration's up() in
 * place, for the same reason that migration was itself a new file rather
 * than an edit to the migration before it: Laravel tracks applied
 * migrations by filename and won't re-run an edited up() against an
 * already-migrated database, so an existing deployment would otherwise
 * keep the now-useless index forever.
 *
 * A complete no-op on every non-pgsql connection, and defensive the same
 * way both of its predecessors are: `php artisan migrate --force` runs on
 * every container start and must never hard-fail a deployment over an
 * index that may not even exist (e.g. a deployment whose Postgres role
 * never had CREATE EXTENSION privileges, so the original index was never
 * successfully created in the first place).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::statement('DROP INDEX IF EXISTS "'.DB::getTablePrefix().'media_cds_tracks_trgm_idx"');
        } catch (Throwable $e) {
            Log::warning('Could not drop the now-unused media_cds.tracks trigram index.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Deliberately does not recreate the index — a rollback of this
     * migration alone shouldn't resurrect an index tied to search query
     * logic (#57's whole-blob matching) that #72 already replaced in the
     * application code; recreating it here would silently decouple the
     * index from what SearchService actually queries.
     */
    public function down(): void {}
};
