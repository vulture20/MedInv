<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GitHub issue #96: extends the pg_trgm indexing from
 * 2026_08_13_120000_add_pg_trgm_indexes_for_media_search.php to the new
 * `location` column (added by the migration immediately before this one,
 * and to SearchService::SEARCHABLE_COLUMNS in the same change) — a
 * separate migration rather than editing that one in place, since Laravel
 * tracks applied migrations by filename and never re-runs an edited up()
 * against an already-migrated database; `location` didn't even exist as a
 * column when that migration ran. Same createTrigramIndex()/
 * dropTrigramIndex() logic (duplicated rather than shared — migration
 * classes are point-in-time snapshots with no stable API to import from
 * each other, the same reasoning
 * 2026_08_17_130000_drop_pg_trgm_index_for_media_cd_tracks.php's own
 * docblock gives), same defensiveness: a no-op on every non-pgsql
 * connection, and each statement failing independently (via
 * $withinTransaction = false + try/catch) rather than aborting the whole
 * migration if e.g. pg_trgm was never actually available.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const TABLES = ['media_books', 'media_cds', 'media_dvd_blurays'];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            $this->createTrigramIndex($table);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            $this->dropTrigramIndex($table);
        }
    }

    private function createTrigramIndex(string $table): void
    {
        try {
            $wrappedTable = DB::getQueryGrammar()->wrapTable($table);
            $wrappedColumn = DB::getQueryGrammar()->wrap('location');
            $indexName = $this->indexName($table);

            DB::statement("CREATE INDEX IF NOT EXISTS \"{$indexName}\" ON {$wrappedTable} USING gin (LOWER({$wrappedColumn}) gin_trgm_ops)");
        } catch (Throwable $e) {
            Log::warning("Could not create trigram index on {$table}.location.", ['error' => $e->getMessage()]);
        }
    }

    private function dropTrigramIndex(string $table): void
    {
        try {
            DB::statement('DROP INDEX IF EXISTS "'.$this->indexName($table).'"');
        } catch (Throwable $e) {
            Log::warning("Could not drop trigram index on {$table}.location.", ['error' => $e->getMessage()]);
        }
    }

    private function indexName(string $table): string
    {
        return DB::getTablePrefix()."{$table}_location_trgm_idx";
    }
};
