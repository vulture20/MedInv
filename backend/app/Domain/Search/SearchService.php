<?php

namespace App\Domain\Search;

use App\Domain\Libraries\LibraryAccessService;
use App\Models\Library;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\MediaDvdBluray;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cross-media-type search over every attribute (briefing 13.), scoped to
 * libraries the requesting user can read (reuses LibraryAccessService so
 * the "not shared -> not findable" rule from 4.3 also applies to search).
 * Each hit carries its source library so results can show provenance (13.).
 */
class SearchService
{
    /**
     * @var array<string, string[]> Searchable text columns per model class.
     *
     * Public (not just used by search() itself): the pg_trgm migration
     * (database/migrations/*_add_pg_trgm_indexes_for_media_search.php)
     * iterates this exact list to build its GIN trigram indexes, rather
     * than keeping a second, driftable copy of the same column list.
     */
    public const SEARCHABLE_COLUMNS = [
        MediaBook::class => ['title', 'description', 'authors', 'format', 'genre', 'language', 'publisher', 'isbn10', 'isbn13', 'ean'],
        MediaCd::class => ['title', 'description', 'artist', 'medium', 'asin', 'ean'],
        MediaDvdBluray::class => ['title', 'description', 'medium', 'languages', 'cast', 'director', 'ean'],
    ];

    public function __construct(private readonly LibraryAccessService $accessService) {}

    public function search(User $user, string $query, bool $fuzzy = false): Collection
    {
        $visibleLibraryIds = $this->accessService->visibleLibrariesQuery($user)->pluck('id', 'id');

        $results = collect();

        foreach (self::SEARCHABLE_COLUMNS as $modelClass => $columns) {
            $items = $fuzzy && ! $this->pgTrgmAvailable()
                ? $this->fuzzyPortableSearch($modelClass, $columns, $visibleLibraryIds, $query)
                : $this->sqlSearch($modelClass, $columns, $visibleLibraryIds, $query, $fuzzy);

            $results = $results->merge($items);
        }

        return $results;
    }

    /**
     * The `!fuzzy` case (unchanged from before #9) and the Postgres/pg_trgm
     * case both stay entirely SQL-side, single round trip per model class.
     */
    private function sqlSearch(string $modelClass, array $columns, Collection $visibleLibraryIds, string $query, bool $fuzzy): Collection
    {
        $useTrigram = $fuzzy && $this->pgTrgmAvailable();

        return $modelClass::query()
            ->whereIn('library_id', $visibleLibraryIds)
            ->where(function ($q) use ($columns, $query, $fuzzy, $useTrigram) {
                foreach ($columns as $column) {
                    // MediaDvdBluray::$SEARCHABLE_COLUMNS includes `cast`, a reserved SQL
                    // keyword (CAST(expr AS type)) — unquoted, `LOWER(cast)` parses as the
                    // start of a CAST expression and blows up with a syntax error. wrap()
                    // applies the connection-appropriate identifier quoting (double quotes
                    // on sqlite/postgres, backticks on mysql/mariadb) instead of hardcoding one.
                    $wrapped = DB::getQueryGrammar()->wrap($column);

                    if ($fuzzy) {
                        $q->orWhereRaw("LOWER({$wrapped}) LIKE ?", ['%'.mb_strtolower($query).'%']);
                    } else {
                        $q->orWhere($column, 'like', "%{$query}%");
                    }

                    if ($useTrigram) {
                        // word_similarity (not plain similarity/%) on purpose: similarity()
                        // compares the *entire* compared strings, so a short query matching
                        // cleanly inside a long `description` gets a tiny score simply because
                        // the denominator scales with the field's total length. word_similarity
                        // instead asks "does the query approximately appear as a substring
                        // somewhere in the column", which is the actually-desired semantic and
                        // what the GIN gin_trgm_ops index (see the pg_trgm migration) accelerates
                        // via the %> operator — confirmed via EXPLAIN against a real Postgres
                        // instance to pick an index (bitmap) scan, not a sequential scan.
                        $q->orWhereRaw("LOWER({$wrapped}) %> LOWER(?)", [$query]);
                    }
                }
            })
            ->with('library:id,name,media_type')
            ->get();
    }

    /**
     * sqlite, MariaDB/MySQL, or a pgsql connection without pg_trgm actually
     * installed: no privilege-free, index-usable native fuzzy primitive
     * exists on any of these for arbitrary-position typos (a typo'd query
     * word has, by definition, no substring overlap with the correctly
     * spelled field value, so no LIKE-based candidate narrowing on the
     * query word itself is possible without risking excluding the very row
     * that should match). Falls back to loading every row in the visible
     * libraries — library-visibility scoping (whereIn library_id) is kept
     * exactly as in the SQL-side path, just evaluated as a WHERE clause
     * rather than a client-side filter — and matching in PHP via
     * FuzzyTextMatcher. Acceptable for this app's realistic data volumes
     * (personal physical-media collections, not web-scale), and no worse
     * big-O than the existing leading-wildcard LIKE, which isn't
     * index-accelerated by any of these engines either.
     */
    private function fuzzyPortableSearch(string $modelClass, array $columns, Collection $visibleLibraryIds, string $query): Collection
    {
        return $modelClass::query()
            ->whereIn('library_id', $visibleLibraryIds)
            ->with('library:id,name,media_type')
            ->get()
            ->filter(function ($item) use ($columns, $query) {
                foreach ($columns as $column) {
                    if (FuzzyTextMatcher::matchesAllWords($query, (string) $item->{$column})) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    /**
     * Cached (not just in-process — SearchService isn't bound as a
     * singleton, so a static property would be rebuilt on effectively every
     * request anyway) check for whether the pg_trgm extension is actually
     * installed, since a self-hosted Postgres instance this app connects to
     * might not grant its DB user CREATE EXTENSION privileges — the
     * migration that attempts to install it is defensive about that (see
     * its docblock) and this must degrade the same way at query time,
     * falling back to fuzzyPortableSearch() even on a pgsql connection.
     */
    private function pgTrgmAvailable(): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return false;
        }

        return (bool) Cache::remember('search.pg_trgm_available', now()->addMinutes(15), function () {
            return (bool) DB::selectOne("SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm'");
        });
    }
}
