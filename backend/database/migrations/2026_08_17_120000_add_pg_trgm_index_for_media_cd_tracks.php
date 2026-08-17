<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Follow-up to 2026_08_13_120000_add_pg_trgm_indexes_for_media_search.php,
 * for GitHub issue #57: MediaCd::tracks joined SearchService::
 * SEARCHABLE_COLUMNS after that migration already ran, so a deployment that
 * applied it before this point never got a trigram index for `tracks` — a
 * NEW migration is needed rather than editing the old one, since Laravel
 * tracks applied migrations by filename and won't re-run an edited `up()`
 * on an already-migrated database.
 *
 * Deliberately its own small migration instead of re-running the original's
 * full loop over SEARCHABLE_COLUMNS: every other column there already has
 * its index from the original migration, so redoing them here would just
 * be redundant (if harmless, thanks to `IF NOT EXISTS`) work.
 *
 * `tracks` is a JSON column (see MediaCd::casts()), not a plain text one —
 * Postgres' json type has no LIKE/pg_trgm operator without an explicit cast
 * to text (an uncast comparison is a SQL type error, not just an empty
 * result), so the indexed expression here is `LOWER((tracks)::text)`,
 * matching the cast SearchService::wrapSearchColumn() applies to the query
 * itself — a GIN index only gets used when its expression matches the
 * query's expression exactly.
 *
 * A complete no-op on every non-pgsql connection, and defensive the same
 * way the original migration is (see its own docblock): `php artisan
 * migrate --force` runs on every container start and must never hard-fail
 * a deployment just because this particular index couldn't be created.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const TABLE = 'media_cds';

    private const COLUMN = 'tracks';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            $wrappedTable = DB::getQueryGrammar()->wrapTable(self::TABLE);
            $wrappedColumn = DB::getQueryGrammar()->wrap(self::COLUMN);
            $indexName = $this->indexName();

            DB::statement("CREATE INDEX IF NOT EXISTS \"{$indexName}\" ON {$wrappedTable} USING gin (LOWER(({$wrappedColumn})::text) gin_trgm_ops)");
        } catch (Throwable $e) {
            Log::warning('Could not create trigram index on '.self::TABLE.'.'.self::COLUMN.'.', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::statement('DROP INDEX IF EXISTS "'.$this->indexName().'"');
        } catch (Throwable $e) {
            Log::warning('Could not drop trigram index on '.self::TABLE.'.'.self::COLUMN.'.', ['error' => $e->getMessage()]);
        }
    }

    /** Table name already carries MEDINV_DB_PREFIX (wrapTable() above), so the index name inherits it too. */
    private function indexName(): string
    {
        return DB::getTablePrefix().self::TABLE.'_'.self::COLUMN.'_trgm_idx';
    }
};
