<?php

use App\Domain\Search\SearchService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Postgres-specific half of GitHub issue #9's typo-tolerant search: enables
 * pg_trgm and adds a GIN trigram index per searchable column (see
 * App\Domain\Search\SearchService::SEARCHABLE_COLUMNS, iterated directly
 * here rather than duplicating the column list) so the fuzzy-search path
 * can use an index-accelerated word_similarity() lookup instead of the
 * portable, full-scan PHP matcher every other supported connection
 * (sqlite, mariadb/mysql) uses.
 *
 * A complete no-op on every non-pgsql connection — driver-specific rather
 * than an allowlist of the other drivers, so any future/unlisted driver
 * automatically gets the safe (portable-search) behavior too.
 *
 * Deliberately defensive: `php artisan migrate --force` runs automatically
 * on every container start (docker/entrypoint.sh) and must never hard-fail
 * a deployment. A self-hosted Postgres instance this app connects to might
 * not grant its DB user CREATE EXTENSION privileges (managed/restricted
 * setups) — if `CREATE EXTENSION` fails, this logs a warning and returns
 * instead of throwing; SearchService::pgTrgmAvailable() checks at query
 * time whether the extension actually ended up installed and falls back to
 * the portable matcher if not, so a privilege-restricted deployment simply
 * gets the same fuzzy behavior as sqlite/mariadb rather than a broken app.
 */
return new class extends Migration
{
    /**
     * Postgres wraps a migration's up() in one transaction by default; a
     * single failed statement (e.g. CREATE EXTENSION lacking privilege)
     * aborts that *entire* transaction, so every later statement — even
     * ones in a catch block, like the Cache::forget() below — fails too
     * with "current transaction is aborted", uncaught, crashing the
     * migration outright (confirmed live against a restricted-privilege
     * Postgres role). Disabling the wrapping transaction lets each
     * DB::statement() succeed or fail independently, which is the whole
     * point of this migration's defensive, "skip what can't be done"
     * design.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (Throwable $e) {
            Log::warning('pg_trgm extension unavailable (insufficient privileges?) — fuzzy search will use the portable matcher instead.', ['error' => $e->getMessage()]);
            Cache::forget('search.pg_trgm_available');

            return;
        }

        foreach (SearchService::SEARCHABLE_COLUMNS as $modelClass => $columns) {
            $table = (new $modelClass)->getTable();

            foreach ($columns as $column) {
                $this->createTrigramIndex($table, $column);
            }
        }

        // So a fresh install (or a deployment that just gained CREATE EXTENSION
        // privileges) reflects the new state immediately rather than waiting out
        // pgTrgmAvailable()'s cache TTL.
        Cache::forget('search.pg_trgm_available');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (SearchService::SEARCHABLE_COLUMNS as $modelClass => $columns) {
            $table = (new $modelClass)->getTable();

            foreach ($columns as $column) {
                $this->dropTrigramIndex($table, $column);
            }
        }

        // Deliberately does NOT `DROP EXTENSION pg_trgm` — other objects on a
        // shared Postgres instance (MEDINV_DB_PREFIX allows sharing one DB
        // server) may depend on it; dropping a shared extension on rollback
        // is unnecessarily destructive for what this migration itself added.
        Cache::forget('search.pg_trgm_available');
    }

    private function createTrigramIndex(string $table, string $column): void
    {
        try {
            $wrappedTable = DB::getQueryGrammar()->wrapTable($table);
            $wrappedColumn = DB::getQueryGrammar()->wrap($column);
            $indexName = $this->indexName($table, $column);

            // Indexed on LOWER(column), not the raw column — SearchService's fuzzy
            // query always wraps the column in LOWER() (case-insensitive search, same
            // as the existing LIKE branch), and a GIN index only gets used for an
            // expression that matches the query *exactly*; confirmed via EXPLAIN
            // against a live Postgres instance that an index built on the bare
            // column left the %> predicate an unindexed Seq Scan filter, while an
            // index on LOWER(column) turned it into a Bitmap Index Scan.
            DB::statement("CREATE INDEX IF NOT EXISTS \"{$indexName}\" ON {$wrappedTable} USING gin (LOWER({$wrappedColumn}) gin_trgm_ops)");
        } catch (Throwable $e) {
            Log::warning("Could not create trigram index on {$table}.{$column}.", ['error' => $e->getMessage()]);
        }
    }

    private function dropTrigramIndex(string $table, string $column): void
    {
        try {
            DB::statement('DROP INDEX IF EXISTS "'.$this->indexName($table, $column).'"');
        } catch (Throwable $e) {
            Log::warning("Could not drop trigram index on {$table}.{$column}.", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Table name already carries MEDINV_DB_PREFIX (getTable() + wrapTable()
     * above), so the index name inherits it too — no separate collision
     * risk beyond what applies to every other MedInv-created identifier.
     */
    private function indexName(string $table, string $column): string
    {
        return DB::getTablePrefix()."{$table}_{$column}_trgm_idx";
    }
};
