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
     * @var array<string, string[]> Searchable plain text columns per model class.
     *
     * Public (not just used by search() itself): the pg_trgm migration
     * (database/migrations/*_add_pg_trgm_indexes_for_media_search.php)
     * iterates this exact list to build its GIN trigram indexes, rather
     * than keeping a second, driftable copy of the same column list.
     *
     * MediaCd::tracks (a JSON array, see MediaCd::casts()) is deliberately
     * NOT listed here — it isn't a plain text column, so it can't go
     * through the uniform wrap()+LIKE loop every entry here does. It's
     * matched separately via JSON_ARRAY_SEARCHABLE_FIELDS below.
     */
    public const SEARCHABLE_COLUMNS = [
        MediaBook::class => ['title', 'description', 'authors', 'format', 'genre', 'language', 'publisher', 'isbn10', 'isbn13', 'ean'],
        MediaCd::class => ['title', 'description', 'artist', 'medium', 'asin', 'ean'],
        MediaDvdBluray::class => ['title', 'description', 'medium', 'languages', 'cast', 'director', 'ean'],
    ];

    /**
     * @var array<string, array<string, string>> Model class => JSON array
     *                                           column => the field within each array element to match precisely.
     *
     * GitHub issue #72 (a follow-up to #57, which first added tracks to
     * search via a coarse whole-JSON-blob-text match): matches only
     * MediaCd::tracks[].title, not position numbers, duration_seconds, or
     * JSON punctuation elsewhere in the blob. Needs genuinely different,
     * native JSON-path SQL per DB backend — no shared, privilege-free
     * syntax exists across sqlite/mariadb/pgsql for "does any element of a
     * JSON array have a given key matching a pattern" the way plain
     * wrap()+LIKE is shared for ordinary columns — see
     * addJsonArrayFieldConditions() for each backend's query, individually
     * live-verified against a real instance of that backend.
     */
    public const JSON_ARRAY_SEARCHABLE_FIELDS = [
        MediaCd::class => ['tracks' => 'title'],
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
        $jsonArrayFields = self::JSON_ARRAY_SEARCHABLE_FIELDS[$modelClass] ?? [];

        return $modelClass::query()
            ->whereIn('library_id', $visibleLibraryIds)
            ->where(function ($q) use ($columns, $query, $fuzzy, $useTrigram, $jsonArrayFields) {
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

                foreach ($jsonArrayFields as $column => $field) {
                    $this->addJsonArrayFieldConditions($q, $column, $field, $query, $useTrigram);
                }
            })
            ->with('library:id,name,media_type')
            ->get();
    }

    /**
     * Adds the OR-condition(s) matching a single field within every element
     * of a JSON array column (issue #72) — e.g. "does any element of
     * MediaCd::tracks have a title matching the query". Genuinely different
     * SQL per backend, each individually live-verified:
     *
     * - **sqlite**: `json_each()` as a correlated table-valued function,
     *   `json_extract()` to pull the field out of each element.
     * - **MariaDB/MySQL**: `JSON_SEARCH()` in 'one' mode with a `$[*].field`
     *   path restricts the search to just that field of each array element;
     *   its search_str parameter accepts SQL LIKE wildcards directly.
     * - **PostgreSQL**: `jsonb_array_elements()` — the column is declared
     *   `json`, not `jsonb` (see the migration that added it), so an
     *   explicit `::jsonb` cast is needed first; `->>'field'` then extracts
     *   the field as text from each element. The fuzzy branch uses
     *   word_similarity's `%>` operator, same semantic as the plain-column
     *   trigram branch above, but unindexed — a GIN index over "any array
     *   element's field" isn't a simple expression index the way a plain
     *   column's `LOWER(column)` is, and this app's realistic data volumes
     *   (a personal collection's track counts) don't warrant the added
     *   complexity of one.
     *
     * $useTrigram (only ever true on a real Postgres connection, see
     * sqlSearch()) adds one further OR-condition on top, using
     * word_similarity for genuine typo tolerance — the LIKE condition above
     * still runs too even then, catching a clean substring match exactly
     * like the plain-column branches above do.
     *
     * An unrecognized driver adds no condition for this field rather than
     * guessing at syntax — degrades to "not found via track title" rather
     * than a broken query.
     */
    private function addJsonArrayFieldConditions($q, string $column, string $field, string $query, bool $useTrigram): void
    {
        $wrapped = DB::getQueryGrammar()->wrap($column);
        $driver = DB::connection()->getDriverName();

        match ($driver) {
            'sqlite' => $q->orWhereRaw(
                "EXISTS (SELECT 1 FROM json_each({$wrapped}) WHERE LOWER(json_extract(value, '\$.{$field}')) LIKE ?)",
                ['%'.mb_strtolower($query).'%']
            ),
            'mysql', 'mariadb' => $q->orWhereRaw(
                "JSON_SEARCH(LOWER({$wrapped}), 'one', ?, NULL, '\$[*].{$field}') IS NOT NULL",
                ['%'.mb_strtolower($query).'%']
            ),
            'pgsql' => $this->addPostgresJsonArrayFieldConditions($q, $wrapped, $field, $query, $useTrigram),
            default => null,
        };
    }

    private function addPostgresJsonArrayFieldConditions($q, string $wrapped, string $field, string $query, bool $useTrigram): void
    {
        $elements = "jsonb_array_elements(({$wrapped})::jsonb) elem";
        $extracted = "elem->>'{$field}'";

        $q->orWhereRaw("EXISTS (SELECT 1 FROM {$elements} WHERE LOWER({$extracted}) LIKE ?)", ['%'.mb_strtolower($query).'%']);

        if ($useTrigram) {
            $q->orWhereRaw("EXISTS (SELECT 1 FROM {$elements} WHERE LOWER({$extracted}) %> LOWER(?))", [$query]);
        }
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
        $jsonArrayFields = self::JSON_ARRAY_SEARCHABLE_FIELDS[$modelClass] ?? [];

        return $modelClass::query()
            ->whereIn('library_id', $visibleLibraryIds)
            ->with('library:id,name,media_type')
            ->get()
            ->filter(function ($item) use ($columns, $query, $jsonArrayFields) {
                foreach ($columns as $column) {
                    if (FuzzyTextMatcher::matchesAllWords($query, (string) $item->{$column})) {
                        return true;
                    }
                }

                // Matches against each array element's field individually (issue
                // #72) rather than the whole tracks array at once — MediaCd::tracks
                // is cast to a PHP array by Eloquent, so this reads real track
                // titles directly, with none of the position-number/duration/JSON-
                // punctuation false positives a whole-blob-text match would have.
                foreach ($jsonArrayFields as $column => $field) {
                    foreach ((array) $item->{$column} as $element) {
                        if (FuzzyTextMatcher::matchesAllWords($query, (string) ($element[$field] ?? ''))) {
                            return true;
                        }
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
