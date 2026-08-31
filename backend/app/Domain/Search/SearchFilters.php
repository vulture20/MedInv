<?php

namespace App\Domain\Search;

/**
 * GitHub issue #73 — the structured, AND-combined filter criteria the old
 * two-parameter search() (`query`, `fuzzy`) grew into: media type/library
 * scoping, a field-specific text search scope instead of always matching
 * every SEARCHABLE_COLUMNS column at once, attribute filters (values that
 * actually occur in the visible collection, see
 * SearchService::filterOptionsFor()), and range filters for price/year/
 * page count/disc count/runtime. A plain constructor-property DTO rather
 * than validating ad hoc inside SearchService itself — SearchController is
 * where the actual `$request->validate()` happens, this is just the typed
 * shape it builds from the validated array, so SearchService's own methods
 * never have to re-derive "is this filter active" from raw, possibly-messy
 * request data.
 *
 * Every array filter defaults to "not restricted" when empty — an empty
 * `mediaTypes` means every media type, not "match nothing"; same for
 * `libraryIds` (every visible library) and the attribute filters
 * (genre/format/language/medium/languages, each simply not applied when
 * empty). Every `*Min`/`*Max` range bound is `null` when not set, meaning
 * "no lower/upper bound" rather than 0.
 */
final class SearchFilters
{
    /**
     * @param  string[]  $mediaTypes  Subset of ['book','cd','dvd_bluray']; empty = all.
     * @param  int[]  $libraryIds  Empty = every visible library.
     * @param  string[]  $genre  Book and DVD/Blu-ray (GitHub issue #140 added the column to the latter) — not CD, which has no `genre` column.
     * @param  string[]  $format  Book-only.
     * @param  string[]  $language  Book-only.
     * @param  string[]  $medium  CD/DVD-Blu-ray.
     * @param  string[]  $languages  DVD-Blu-ray-only (the `languages` column is a comma-separated multi-value list).
     * @param  int|null  $runtimeMin  Minutes — CD's own `runtime_seconds` is converted, DVD's `runtime_minutes` used as-is.
     * @param  int|null  $runtimeMax  Minutes, see $runtimeMin.
     */
    public function __construct(
        public readonly ?string $query,
        public readonly bool $fuzzy,
        // GitHub issue #209 — has_duplicates=true OR duplicate_count>0.
        // Same reasoning as $fuzzy for staying a dedicated constructor
        // parameter rather than a fromValidated() $data key: a query-string
        // GET param serializes a JS `false` as the literal string "false",
        // which Laravel's `boolean` validation rule rejects with a 422 (see
        // SearchController::filtersFromRequest()'s own comment on $fuzzy).
        public readonly bool $duplicates,
        public readonly string $field,
        public readonly array $mediaTypes,
        public readonly array $libraryIds,
        public readonly array $genre,
        public readonly array $format,
        public readonly array $language,
        public readonly array $medium,
        public readonly array $languages,
        public readonly ?float $priceMin,
        public readonly ?float $priceMax,
        public readonly ?int $yearMin,
        public readonly ?int $yearMax,
        public readonly ?int $pageCountMin,
        public readonly ?int $pageCountMax,
        public readonly ?int $discCountMin,
        public readonly ?int $discCountMax,
        public readonly ?int $runtimeMin,
        public readonly ?int $runtimeMax,
    ) {}

    /** Builds from an already-`$request->validate()`d array (SearchController::__invoke()) — every key optional, same "not restricted"/`null` defaults the constructor docblock describes. */
    public static function fromValidated(array $data, bool $fuzzy, bool $duplicates): self
    {
        return new self(
            query: $data['query'] ?? null,
            fuzzy: $fuzzy,
            duplicates: $duplicates,
            field: $data['field'] ?? 'all',
            mediaTypes: $data['media_types'] ?? [],
            libraryIds: $data['library_ids'] ?? [],
            genre: $data['genre'] ?? [],
            format: $data['format'] ?? [],
            language: $data['language'] ?? [],
            medium: $data['medium'] ?? [],
            languages: $data['languages'] ?? [],
            priceMin: isset($data['price_min']) ? (float) $data['price_min'] : null,
            priceMax: isset($data['price_max']) ? (float) $data['price_max'] : null,
            yearMin: isset($data['year_min']) ? (int) $data['year_min'] : null,
            yearMax: isset($data['year_max']) ? (int) $data['year_max'] : null,
            pageCountMin: isset($data['page_count_min']) ? (int) $data['page_count_min'] : null,
            pageCountMax: isset($data['page_count_max']) ? (int) $data['page_count_max'] : null,
            discCountMin: isset($data['disc_count_min']) ? (int) $data['disc_count_min'] : null,
            discCountMax: isset($data['disc_count_max']) ? (int) $data['disc_count_max'] : null,
            runtimeMin: isset($data['runtime_min']) ? (int) $data['runtime_min'] : null,
            runtimeMax: isset($data['runtime_max']) ? (int) $data['runtime_max'] : null,
        );
    }

    public function hasQuery(): bool
    {
        return $this->query !== null && $this->query !== '';
    }
}
