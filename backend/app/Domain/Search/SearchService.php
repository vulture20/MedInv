<?php

namespace App\Domain\Search;

use App\Domain\Libraries\LibraryAccessService;
use App\Domain\Libraries\MediaItemService;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\MediaDvdBluray;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cross-media-type search over every attribute (briefing 13.), scoped to
 * libraries the requesting user can read (reuses LibraryAccessService so
 * the "not shared -> not findable" rule from 4.3 also applies to search).
 * Each hit carries its source library so results can show provenance (13.).
 *
 * GitHub issue #73 grew this from a plain (query, fuzzy) free-text search
 * into a real filter mask: media type/library scoping, a field-specific
 * text search scope (FIELD_GROUPS) instead of always matching every
 * SEARCHABLE_COLUMNS column at once, attribute filters fed by
 * filterOptionsFor()'s "values that actually occur" lists (mirroring
 * StatisticsService::distributionsFor()'s own precedent for that), and
 * range filters for price/year/page count/disc count/runtime. See
 * SearchFilters' own docblock for the full shape; search() itself stays a
 * thin per-media-type dispatcher, all of the actual query building lives
 * in sqlSearch()/applyStructuralFilters() below.
 *
 * Also home to randomItemsFor() (GitHub issue #116) — a different query
 * shape (no text query, one media type at a time) but the same
 * visibility-scoped, full-model-plus-library read as search() above, so it
 * lives here rather than in a new one-method domain of its own.
 */
class SearchService
{
    /**
     * @var array<string, string[]> Searchable plain text columns per model class.
     *
     * Public (not just used by search() itself): the pg_trgm migration
     * (database/migrations/*_add_pg_trgm_indexes_for_media_search.php)
     * iterated this exact list, at the time it ran, to build its GIN
     * trigram indexes, rather than keeping a second, driftable copy of the
     * same column list — a column added to this array *after* that
     * migration already ran (e.g. `location`, GitHub issue #96) needs its
     * own small follow-up migration for the trigram index specifically
     * (2026_08_19_100001_add_pg_trgm_index_for_media_location.php), since
     * Laravel never re-runs an already-applied migration's up() against
     * an existing database.
     *
     * MediaCd::tracks (a JSON array, see MediaCd::casts()) is deliberately
     * NOT listed here — it isn't a plain text column, so it can't go
     * through the uniform wrap()+LIKE loop every entry here does. It's
     * matched separately via JSON_ARRAY_SEARCHABLE_FIELDS below.
     */
    public const SEARCHABLE_COLUMNS = [
        MediaBook::class => ['title', 'description', 'authors', 'format', 'genre', 'language', 'publisher', 'isbn10', 'isbn13', 'ean', 'location'],
        MediaCd::class => ['title', 'description', 'artist', 'medium', 'asin', 'ean', 'location'],
        // GitHub issue #140: 'genre'/'subtitles' added alongside the columns they were added next to.
        MediaDvdBluray::class => ['title', 'description', 'medium', 'languages', 'subtitles', 'cast', 'director', 'genre', 'ean', 'location'],
    ];

    /**
     * @var array<string, array<string, string[]>> `field` param (GitHub
     *                                             issue #73) => model class => the subset of that model's
     *                                             SEARCHABLE_COLUMNS to match against — the field-specific search
     *                                             scope ("nur Titel", "nur Autor/Interpret/Regisseur", ...) the
     *                                             issue proposed as an alternative to always matching every
     *                                             column at once. `'all'` (the default) isn't listed here; it's
     *                                             handled directly by columnsFor() as "every column in
     *                                             SEARCHABLE_COLUMNS", same as before this issue. `'tracks'`
     *                                             isn't listed either — it has no plain columns at all, only the
     *                                             JSON_ARRAY_SEARCHABLE_FIELDS match (CD track titles), see
     *                                             jsonArrayFieldsFor().
     */
    private const FIELD_GROUPS = [
        'title' => [
            MediaBook::class => ['title'],
            MediaCd::class => ['title'],
            MediaDvdBluray::class => ['title'],
        ],
        // "Autor/Interpret/Regisseur" (briefing 6.1-6.3's per-media-type
        // creator field) — grouped under one filter option since asking a
        // user to pick which of the three synonyms applies before they've
        // even chosen a media type doesn't make sense; mediaTypes already
        // narrows which of these three actually gets queried.
        'creator' => [
            MediaBook::class => ['authors'],
            MediaCd::class => ['artist'],
            MediaDvdBluray::class => ['director'],
        ],
        'description' => [
            MediaBook::class => ['description'],
            MediaCd::class => ['description'],
            MediaDvdBluray::class => ['description'],
        ],
        // "ISBN/EAN" — a book's isbn10/isbn13 are additional identifiers
        // alongside its ean; CD/DVD-Blu-ray only ever have the ean.
        'identifier' => [
            MediaBook::class => ['isbn10', 'isbn13', 'ean'],
            MediaCd::class => ['ean'],
            MediaDvdBluray::class => ['ean'],
        ],
        'location' => [
            MediaBook::class => ['location'],
            MediaCd::class => ['location'],
            MediaDvdBluray::class => ['location'],
        ],
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

    /** GitHub issue #116 — how many random items DashboardPage.tsx's cover carousel shows per media type. */
    private const RANDOM_ITEMS_LIMIT = 25;

    public function __construct(
        private readonly LibraryAccessService $accessService,
        private readonly MediaItemService $mediaItemService,
    ) {}

    /**
     * GitHub issue #73. Every media type not excluded by
     * `$filters->mediaTypes` is queried independently and merged — same
     * "one query per model class, merge the collections" shape search()
     * always had, `$filters` just now carries a lot more than `query`/
     * `fuzzy` into how each of those per-model queries is built.
     */
    public function search(User $user, SearchFilters $filters): Collection
    {
        $visibleLibraryIds = $this->accessService->visibleLibrariesQuery($user)
            ->when($filters->libraryIds !== [], fn (Builder $q) => $q->whereIn('id', $filters->libraryIds))
            ->pluck('id', 'id');

        $mediaTypes = $filters->mediaTypes !== [] ? $filters->mediaTypes : ['book', 'cd', 'dvd_bluray'];
        $results = collect();

        foreach ($mediaTypes as $mediaType) {
            $modelClass = $this->mediaItemService->modelClassFor($mediaType);

            $items = $filters->fuzzy && ! $this->pgTrgmAvailable()
                ? $this->fuzzyPortableSearch($modelClass, $visibleLibraryIds, $filters)
                : $this->sqlSearch($modelClass, $visibleLibraryIds, $filters);

            $results = $results->merge($items);
        }

        return $results;
    }

    /**
     * GitHub issue #73's attribute filters (genre/format/language for
     * books, medium for CD/DVD-Blu-ray, languages for DVD-Blu-ray) are
     * populated from values that actually occur in the visible collection,
     * not a hardcoded list — same "denkbar ähnlich der bereits vorhandenen
     * Verteilungs-Statistiken" reasoning the issue itself points at
     * (StatisticsService::distributionsFor()), just distinct values across
     * every visible library at once rather than counts grouped per
     * library. Feeds SearchPage.tsx's filter <select>s.
     *
     * @return array{book: array{genre: string[], format: string[], language: string[]}, cd: array{medium: string[]}, dvd_bluray: array{medium: string[], languages: string[], genre: string[]}}
     */
    public function filterOptionsFor(User $user): array
    {
        $visibleLibraryIds = $this->accessService->visibleLibrariesQuery($user)->pluck('id', 'id');

        return [
            'book' => [
                // GitHub issue #204: `genre` can hold a comma-separated
                // list too (e.g. "Fiction, Fantasy") — same split as
                // `languages`/`medium` below, so the merged genre <select>
                // (see dvd_bluray's own entry) offers each genre on its
                // own instead of one option per distinct combination.
                'genre' => $this->distinctMultiValues(MediaBook::class, 'genre', $visibleLibraryIds),
                'format' => $this->distinctValues(MediaBook::class, 'format', $visibleLibraryIds),
                'language' => $this->distinctValues(MediaBook::class, 'language', $visibleLibraryIds),
            ],
            'cd' => [
                'medium' => $this->distinctMultiValues(MediaCd::class, 'medium', $visibleLibraryIds),
            ],
            'dvd_bluray' => [
                // `medium` can also hold a comma-separated list (e.g. a
                // combo pack's "DVD, Blu-ray") — same split treatment.
                'medium' => $this->distinctMultiValues(MediaDvdBluray::class, 'medium', $visibleLibraryIds),
                // `languages` is a comma-separated multi-value column
                // (e.g. "Deutsch, Englisch") — same split StatisticsService::
                // multiValueDistribution() already does, just without counts.
                // GitHub issue #205: each split token also gets its
                // "Tonart" annotation stripped — see
                // stripLanguageTonartAnnotation()'s own docblock.
                'languages' => $this->distinctMultiValues(MediaDvdBluray::class, 'languages', $visibleLibraryIds, $this->stripLanguageTonartAnnotation(...)),
                // GitHub issue #140: shares SearchFilters::$genre with book's own entry above — SearchFilterPanel.tsx merges both option lists into one <select>, same as it already does for medium (cd+dvd_bluray).
                'genre' => $this->distinctMultiValues(MediaDvdBluray::class, 'genre', $visibleLibraryIds),
            ],
        ];
    }

    /** @return string[] Sorted, unique, non-empty values actually present. */
    private function distinctValues(string $modelClass, string $column, Collection $visibleLibraryIds): array
    {
        return $modelClass::query()
            ->whereIn('library_id', $visibleLibraryIds)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }

    /**
     * @param  ?callable(string): string  $tokenTransform  Applied to each
     *                                                     already comma-split token before dedup — only `languages` passes
     *                                                     one (stripLanguageTonartAnnotation() below); every other caller
     *                                                     (genre, medium) leaves this null and is completely unaffected.
     * @return string[] Sorted, unique, non-empty individual values after splitting each row's comma-separated list.
     */
    private function distinctMultiValues(string $modelClass, string $column, Collection $visibleLibraryIds, ?callable $tokenTransform = null): array
    {
        return $modelClass::query()
            ->whereIn('library_id', $visibleLibraryIds)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->pluck($column)
            ->flatMap(function (string $value) use ($tokenTransform) {
                $tokens = array_map('trim', explode(',', $value));

                return $tokenTransform !== null ? array_map($tokenTransform, $tokens) : $tokens;
            })
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * GitHub issue #205: a user reported that in their real, already-stored
     * data, individual language values inside `languages` (already split on
     * comma by distinctMultiValues()/StatisticsService::
     * multiValueDistribution() above/below) carry a trailing "Tonart"
     * (audio format) annotation contaminating the language name itself —
     * either parenthetical ("Deutsch (DD 5.1)") or a colon suffix
     * ("Englisch: DTS-HD MA"). Different audio tracks for the same film
     * otherwise produce different-looking strings for what is really the
     * same language (e.g. "Deutsch (DD 5.1)" vs "Deutsch (DD 2.0)"),
     * duplicating it in both this filter's facet list and
     * StatisticsService's languages distribution. This is live production
     * data as reported by the user, not a shape independently confirmed
     * against any metadata provider's own captured output (every DVD/
     * Blu-ray provider — Amazon, JPC, Claude, OpenAI, Gemini, Mistral,
     * UpcMdb, TMDB — was checked and produces only plain "Deutsch,
     * Englisch"-style values) — so this cleans already-stored data at read
     * time here, rather than at capture time in a provider the way
     * JpcScraping::stripJpcGenreAnnotation()/AmazonScraping::
     * stripAmazonFormatContamination() do; a provider-level fix alone would
     * leave every already-captured item's duplication exactly as broken.
     *
     * Strips whichever form is present (defensively handles both at once,
     * though only one is expected per value) so both collapse to the same
     * plain language name.
     */
    private function stripLanguageTonartAnnotation(string $language): string
    {
        $withoutParenthetical = preg_replace('/\s*\([^)]*\)\s*$/u', '', $language) ?? $language;
        $withoutColonSuffix = preg_replace('/\s*:.*$/u', '', $withoutParenthetical) ?? $withoutParenthetical;

        return trim($withoutColonSuffix);
    }

    /** The `!fuzzy` case (unchanged from before #9) and the Postgres/pg_trgm case both stay entirely SQL-side, single round trip per model class — GitHub issue #73's structural filters (media type/library already scoped by search(); everything else in applyStructuralFilters()) ride along as plain AND conditions on the same query. */
    private function sqlSearch(string $modelClass, Collection $visibleLibraryIds, SearchFilters $filters): Collection
    {
        $useTrigram = $filters->fuzzy && $this->pgTrgmAvailable();
        $columns = $this->columnsFor($modelClass, $filters->field);
        $jsonArrayFields = $this->jsonArrayFieldsFor($modelClass, $filters->field);

        // GitHub issue #124 — a narrow `field` scope (e.g. 'tracks', which
        // only MediaCd has anything to match) leaves both $columns and
        // $jsonArrayFields empty for a model that field doesn't apply to at
        // all. Laravel's where(Closure) below silently adds *no* WHERE
        // clause whatsoever when the closure ends up adding zero
        // conditions (Builder::addNestedWhereQuery() only wraps the nested
        // group at all if it actually has wheres) — so without this guard,
        // "nothing to match the query against" quietly became "match every
        // row", returning e.g. every book/DVD-Blu-ray for a `field=tracks`
        // search that could only ever apply to CDs. fuzzyPortableSearch()
        // below doesn't have this bug: its ->filter() closure with the same
        // two empty loops correctly falls through to `return false` for
        // every item instead.
        if ($filters->hasQuery() && $columns === [] && $jsonArrayFields === []) {
            return collect();
        }

        $query = $modelClass::query()->whereIn('library_id', $visibleLibraryIds);

        if ($filters->hasQuery()) {
            $query->where(function ($q) use ($columns, $filters, $useTrigram, $jsonArrayFields) {
                foreach ($columns as $column) {
                    // MediaDvdBluray::$SEARCHABLE_COLUMNS includes `cast`, a reserved SQL
                    // keyword (CAST(expr AS type)) — unquoted, `LOWER(cast)` parses as the
                    // start of a CAST expression and blows up with a syntax error. wrap()
                    // applies the connection-appropriate identifier quoting (double quotes
                    // on sqlite/postgres, backticks on mysql/mariadb) instead of hardcoding one.
                    $wrapped = DB::getQueryGrammar()->wrap($column);

                    if ($filters->fuzzy) {
                        $q->orWhereRaw("LOWER({$wrapped}) LIKE ?", ['%'.mb_strtolower($filters->query).'%']);
                    } else {
                        $q->orWhere($column, 'like', "%{$filters->query}%");
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
                        $q->orWhereRaw("LOWER({$wrapped}) %> LOWER(?)", [$filters->query]);
                    }
                }

                foreach ($jsonArrayFields as $column => $field) {
                    $this->addJsonArrayFieldConditions($q, $column, $field, $filters->query, $useTrigram);
                }
            });
        }

        $query = $this->applyStructuralFilters($query, $modelClass, $filters);

        if ($query === null) {
            return collect();
        }

        // `library.owner` (GitHub issue #100) — SearchPage.tsx now opens a
        // hit's MediaItemDetailDialog in place rather than navigating to
        // the owning library, and that dialog's write-access gating
        // (mirrors LibraryAccessService::canWrite()) needs `library.owner.id`
        // client-side, the same way LibraryDetailPage's own `library` prop
        // already carries it.
        return $query->with(['library:id,name,media_type,owner_id', 'library.owner:id,name'])->get();
    }

    /** `field` => the subset of $modelClass's SEARCHABLE_COLUMNS to match — see FIELD_GROUPS' own docblock. */
    private function columnsFor(string $modelClass, string $field): array
    {
        if ($field === 'all') {
            return self::SEARCHABLE_COLUMNS[$modelClass];
        }

        return self::FIELD_GROUPS[$field][$modelClass] ?? [];
    }

    /** JSON_ARRAY_SEARCHABLE_FIELDS only applies in 'all' mode (unchanged pre-#73 behavior) or when the field scope is explicitly 'tracks' (CD track titles only, no plain columns at all — columnsFor() returns [] for it). Any other specific field (e.g. 'title') deliberately excludes track titles, matching that field's own narrower scope. */
    private function jsonArrayFieldsFor(string $modelClass, string $field): array
    {
        return in_array($field, ['all', 'tracks'], true) ? (self::JSON_ARRAY_SEARCHABLE_FIELDS[$modelClass] ?? []) : [];
    }

    /**
     * GitHub issue #73's attribute/range filters, applied as plain AND
     * conditions on top of whatever sqlSearch()/fuzzyPortableSearch()
     * already built. Returns `null` instead of the query when an active
     * filter simply cannot match this model class at all (e.g. a `genre`
     * filter against MediaCd, which has no `genre` column) — the caller
     * treats that as "this media type contributes nothing", the same
     * outcome as if the query itself had run and matched zero rows, just
     * without actually running a query that could never match.
     */
    private function applyStructuralFilters(Builder $query, string $modelClass, SearchFilters $filters): ?Builder
    {
        if ($filters->priceMin !== null) {
            $query->where('price', '>=', $filters->priceMin);
        }
        if ($filters->priceMax !== null) {
            $query->where('price', '<=', $filters->priceMax);
        }

        // DVD-Blu-ray carries a dedicated `production_year` column
        // alongside `release_date` — same more-direct source
        // StatisticsService::distributionsFor() already prefers for DVD's
        // own year distribution, over deriving a year from release_date
        // the way book/CD have to.
        if ($modelClass === MediaDvdBluray::class) {
            if ($filters->yearMin !== null) {
                $query->where('production_year', '>=', $filters->yearMin);
            }
            if ($filters->yearMax !== null) {
                $query->where('production_year', '<=', $filters->yearMax);
            }
        } else {
            if ($filters->yearMin !== null) {
                $query->whereYear('release_date', '>=', $filters->yearMin);
            }
            if ($filters->yearMax !== null) {
                $query->whereYear('release_date', '<=', $filters->yearMax);
            }
        }

        // `format`/`language`/`page_count` remain book-only.
        $bookOnlyAttributeActive = $filters->format !== [] || $filters->language !== []
            || $filters->pageCountMin !== null || $filters->pageCountMax !== null;
        if ($bookOnlyAttributeActive && $modelClass !== MediaBook::class) {
            return null;
        }
        if ($modelClass === MediaBook::class) {
            foreach (['format' => $filters->format, 'language' => $filters->language] as $column => $values) {
                if ($values !== []) {
                    $query->whereIn($column, $values);
                }
            }
            if ($filters->pageCountMin !== null) {
                $query->where('page_count', '>=', $filters->pageCountMin);
            }
            if ($filters->pageCountMax !== null) {
                $query->where('page_count', '<=', $filters->pageCountMax);
            }
        }

        // GitHub issue #140: `genre` is shared by book and DVD/Blu-ray
        // (both have a `genre` column) — the one attribute filter that
        // isn't exclusively book-only, so it's handled separately from
        // the book-only group above rather than excluding DVD/Blu-ray
        // from the whole search whenever a genre filter is active.
        if ($filters->genre !== [] && $modelClass === MediaCd::class) {
            return null;
        }
        if ($filters->genre !== [] && $modelClass !== MediaCd::class) {
            // `genre` is a comma-separated multi-value column (see
            // distinctMultiValues() above) — same substring-membership
            // OR loop `languages` below already uses, not a plain
            // whereIn(), which would only ever match a row whose genre is
            // *exactly* one requested value with nothing else alongside it.
            $query->where(function (Builder $q) use ($filters) {
                foreach ($filters->genre as $genre) {
                    $q->orWhere('genre', 'like', '%'.$genre.'%');
                }
            });
        }

        if ($filters->languages !== [] && $modelClass !== MediaDvdBluray::class) {
            return null;
        }
        if ($modelClass === MediaDvdBluray::class && $filters->languages !== []) {
            // `languages` is a comma-separated multi-value column (see
            // distinctMultiValues() above) — a plain LIKE per requested
            // language, OR'd together, same substring-membership check
            // StatisticsService's own distribution takes for granted.
            // GitHub issue #205: no change needed here for the "Tonart"
            // stripping distinctMultiValues() now does — $filters->languages
            // carries the already-cleaned facet value (e.g. "Deutsch"),
            // which still substring-matches a raw, still-annotated stored
            // value like "Deutsch (DD 5.1)" via this same LIKE.
            $query->where(function (Builder $q) use ($filters) {
                foreach ($filters->languages as $language) {
                    $q->orWhere('languages', 'like', '%'.$language.'%');
                }
            });
        }

        $mediumActive = $filters->medium !== [];
        if ($mediumActive && $modelClass === MediaBook::class) {
            return null;
        }
        if ($mediumActive && $modelClass !== MediaBook::class) {
            // `medium` is a comma-separated multi-value column too (e.g. a
            // combo pack's "DVD, Blu-ray") — same OR-of-LIKE treatment as
            // `genre`/`languages` above, for the same reason.
            $query->where(function (Builder $q) use ($filters) {
                foreach ($filters->medium as $medium) {
                    $q->orWhere('medium', 'like', '%'.$medium.'%');
                }
            });
        }

        $discCountActive = $filters->discCountMin !== null || $filters->discCountMax !== null;
        if ($discCountActive && $modelClass === MediaBook::class) {
            return null;
        }
        if ($modelClass !== MediaBook::class) {
            if ($filters->discCountMin !== null) {
                $query->where('disc_count', '>=', $filters->discCountMin);
            }
            if ($filters->discCountMax !== null) {
                $query->where('disc_count', '<=', $filters->discCountMax);
            }
        }

        $runtimeActive = $filters->runtimeMin !== null || $filters->runtimeMax !== null;
        if ($runtimeActive && $modelClass === MediaBook::class) {
            return null;
        }
        if ($modelClass === MediaCd::class) {
            // MediaCd::runtime_seconds vs. the filter's own minutes unit —
            // converted here rather than asking the frontend to think in
            // seconds for one media type and minutes for another.
            if ($filters->runtimeMin !== null) {
                $query->where('runtime_seconds', '>=', $filters->runtimeMin * 60);
            }
            if ($filters->runtimeMax !== null) {
                $query->where('runtime_seconds', '<=', $filters->runtimeMax * 60);
            }
        } elseif ($modelClass === MediaDvdBluray::class) {
            if ($filters->runtimeMin !== null) {
                $query->where('runtime_minutes', '>=', $filters->runtimeMin);
            }
            if ($filters->runtimeMax !== null) {
                $query->where('runtime_minutes', '<=', $filters->runtimeMax);
            }
        }

        // GitHub issue #209 — `has_duplicates`/`duplicate_count` (issue #208)
        // now exist identically on all three media tables, so unlike the
        // filters above this needs no per-model-class `return null` exclusion.
        if ($filters->duplicates) {
            $query->where(function (Builder $q) {
                $q->where('has_duplicates', true)->orWhere('duplicate_count', '>', 0);
            });
        }

        return $query;
    }

    /**
     * A fresh, random selection per media type across every library visible
     * to `$user` (GitHub issue #116) — feeds DashboardPage.tsx's three cover
     * carousels ("CD"/"Buch"/"DVD/Blu-ray"). Re-run on every page load
     * rather than cached, so each visit shows a different slice of the
     * collection.
     *
     * Deliberately not filtered by the visible library's own `media_type`
     * before querying: a MediaBook row can only ever exist in a `book`
     * library to begin with (MediaItemService::create() enforces that at
     * write time via modelClassFor()), so `whereIn('library_id', ...)`
     * against the *full* set of visible library ids already can't match a
     * row of the wrong type — same reasoning ReportsService::topForModel()
     * already relies on.
     *
     * Returns full models (like search() above), not ReportsService's
     * itemSummary() — a tile's click target opens MediaItemDetailDialog
     * directly, which needs every media-type-specific field the same way
     * SearchHit (SearchPage.tsx) does, not just the handful of summary
     * columns a report table shows.
     *
     * @return array<string, Collection> Keyed 'book'/'cd'/'dvd_bluray'.
     */
    public function randomItemsFor(User $user): array
    {
        // GitHub issue #179 — the "Von Startseite ausschließen" preference,
        // same LibraryAccessService::visibleLibrariesQueryExcluding() every
        // other per-user reporting exclusion (Statistics/Reports) already
        // uses. New with this issue: the Startseite carousels had no
        // exclusion mechanism at all before.
        $visibleLibraryIds = $this->accessService->visibleLibrariesQueryExcluding($user, 'exclude_from_dashboard')->pluck('id', 'id');

        $result = [];
        foreach (['book', 'cd', 'dvd_bluray'] as $mediaType) {
            $modelClass = $this->mediaItemService->modelClassFor($mediaType);

            $result[$mediaType] = $modelClass::query()
                ->whereIn('library_id', $visibleLibraryIds)
                // Same `library.owner` reasoning as search()'s own eager load
                // above — the carousel's click handler needs it to gate
                // MediaItemDetailDialog's write-access UI client-side.
                ->with(['library:id,name,media_type,owner_id', 'library.owner:id,name'])
                ->inRandomOrder()
                ->limit(self::RANDOM_ITEMS_LIMIT)
                ->get();
        }

        return $result;
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
     * libraries — library-visibility scoping (whereIn library_id) and
     * GitHub issue #73's structural filters are kept exactly as in the
     * SQL-side path (still evaluated as WHERE clauses, not a client-side
     * filter — only the free-text match itself needs PHP-side matching)
     * and matched in PHP via FuzzyTextMatcher. Acceptable for this app's
     * realistic data volumes (personal physical-media collections, not
     * web-scale), and no worse big-O than the existing leading-wildcard
     * LIKE, which isn't index-accelerated by any of these engines either.
     */
    private function fuzzyPortableSearch(string $modelClass, Collection $visibleLibraryIds, SearchFilters $filters): Collection
    {
        $columns = $this->columnsFor($modelClass, $filters->field);
        $jsonArrayFields = $this->jsonArrayFieldsFor($modelClass, $filters->field);

        $query = $this->applyStructuralFilters(
            $modelClass::query()->whereIn('library_id', $visibleLibraryIds),
            $modelClass,
            $filters
        );

        if ($query === null) {
            return collect();
        }

        // Same `library.owner` reasoning as sqlSearch() above.
        $items = $query->with(['library:id,name,media_type,owner_id', 'library.owner:id,name'])->get();

        if (! $filters->hasQuery()) {
            // Nothing to fuzzy-match against — the structural filters above
            // already did all the narrowing this request asked for.
            return $items;
        }

        return $items
            ->filter(function ($item) use ($columns, $filters, $jsonArrayFields) {
                foreach ($columns as $column) {
                    if (FuzzyTextMatcher::matchesAllWords($filters->query, (string) $item->{$column})) {
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
                        if (FuzzyTextMatcher::matchesAllWords($filters->query, (string) ($element[$field] ?? ''))) {
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
