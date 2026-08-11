<?php

namespace App\Domain\Search;

use App\Domain\Libraries\LibraryAccessService;
use App\Models\Library;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\MediaDvdBluray;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Cross-media-type search over every attribute (briefing 13.), scoped to
 * libraries the requesting user can read (reuses LibraryAccessService so
 * the "not shared -> not findable" rule from 4.3 also applies to search).
 * Each hit carries its source library so results can show provenance (13.).
 */
class SearchService
{
    /** @var array<string, string[]> Searchable text columns per model class. */
    private const SEARCHABLE_COLUMNS = [
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
            $items = $modelClass::query()
                ->whereIn('library_id', $visibleLibraryIds)
                ->where(function ($q) use ($columns, $query, $fuzzy) {
                    foreach ($columns as $column) {
                        $fuzzy
                            ? $q->orWhereRaw('LOWER('.$column.') LIKE ?', ['%'.mb_strtolower($query).'%'])
                            : $q->orWhere($column, 'like', "%{$query}%");
                    }
                })
                ->with('library:id,name,media_type')
                ->get();

            $results = $results->merge($items);
        }

        // TODO: the `fuzzy` flag currently only relaxes case-sensitivity. Wire up a
        // real fuzzy/typo-tolerant matcher (e.g. trigram similarity in Postgres,
        // SOUNDEX, or a Levenshtein post-filter) per briefing 13. — the exact
        // approach should be chosen per selected DB backend for best index usage.
        return $results;
    }
}
