<?php

namespace App\Domain\Reports;

use App\Domain\Libraries\LibraryAccessService;
use App\Models\Library;
use App\Models\LibraryShare;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\MediaDvdBluray;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * "Auswertungen" (GitHub issue #74) — a new, standalone domain module
 * (briefing 10.'s "ein Namensraum je Fachmodul") deliberately separate from
 * App\Domain\Statistics: a Statistik is a chart/aggregated sum (per-library
 * item count and value, distributions, value-over-time), while an
 * Auswertung is a table a user browses row by row. Most rows below are
 * individual media items (id/title/ean/library) — duplicatesFor(),
 * dataQualityFor(), topListsFor(), recentAdditionsFor(), captureSourceFor()
 * — but sharingFor()/userActivityFor() (GitHub issue #74's sharing overview
 * and per-user capture activity) are tables too, just of libraries/users
 * rather than media items; they briefly lived in StatisticsService instead
 * on the theory that "no individual item referenced" made them a Statistik,
 * until GitHub issue #103 pointed out that a browsable table is an
 * Auswertung either way, item-level or not — see StatisticsService's own
 * docblock for that history.
 *
 * Every method is scoped through LibraryAccessService::visibleLibrariesQuery(),
 * exactly like SearchService/StatisticsService, so an unshared library
 * contributes nothing to any of these — briefing 4.3's "weder sichtbar noch
 * auffindbar" applies here just as much as to search results or statistics.
 */
class ReportsService
{
    private const MEDIA_MODEL_CLASSES = [MediaBook::class, MediaCd::class, MediaDvdBluray::class];

    /**
     * @var array<class-string<Model>, string[]> Which columns count as
     *                                           "core" for that media type beyond the three universal ones
     *                                           (cover/description/price, explicitly named in #74) — one
     *                                           judgment-call field per type whose absence is a genuine, common
     *                                           gap: a book with no page count, a CD with no track list (#48), a
     *                                           DVD/Blu-ray with no runtime.
     */
    private const CORE_FIELDS = [
        MediaBook::class => ['cover_path', 'description', 'price', 'page_count'],
        MediaCd::class => ['cover_path', 'description', 'price', 'tracks'],
        MediaDvdBluray::class => ['cover_path', 'description', 'price', 'runtime_minutes'],
    ];

    private const TOP_N = 10;

    public function __construct(private readonly LibraryAccessService $accessService) {}

    /**
     * Items sharing the same EAN across more than one visible library
     * (#74's main proposal) — grouped by media type first, then by EAN,
     * since the same EAN on a book and on a DVD/Blu-ray is coincidence, not
     * a duplicate (#74's own correction comment): briefing 5.1's per-library
     * duplicate-EAN rule only ever compared within one media type's table to
     * begin with, so this mirrors that scope rather than a query spanning
     * all three.
     *
     * @return array<int, array{ean: string, media_type: string, items: array}>
     */
    public function duplicatesFor(User $user): array
    {
        $visibleLibraryIds = $this->visibleLibraryIds($user);

        $groups = collect();
        foreach (self::MEDIA_MODEL_CLASSES as $modelClass) {
            $groups = $groups->merge($this->duplicateGroupsForModel($modelClass, $visibleLibraryIds));
        }

        return $groups->sortBy('ean')->values()->all();
    }

    /** @return array<int, array{ean: string, media_type: string, items: array}> */
    private function duplicateGroupsForModel(string $modelClass, Collection $visibleLibraryIds): array
    {
        $duplicateEans = $modelClass::query()
            ->whereIn('library_id', $visibleLibraryIds)
            ->select('ean')
            ->groupBy('ean')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ean');

        if ($duplicateEans->isEmpty()) {
            return [];
        }

        return $modelClass::query()
            ->whereIn('library_id', $visibleLibraryIds)
            ->whereIn('ean', $duplicateEans)
            ->with('library:id,name,media_type')
            ->get()
            ->groupBy('ean')
            ->map(fn (Collection $items, string $ean) => [
                'ean' => $ean,
                'media_type' => $items->first()->library->media_type,
                'items' => $items->map(fn (Model $item) => $this->itemSummary($item))->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Items missing at least one "core" field (CORE_FIELDS above) —
     * #74's "Datenqualität/Vollständigkeit" idea, most useful right after a
     * manual entry with no metadata match at all (briefing 7.1).
     *
     * @return array<int, array{missing_fields: string[]}>
     */
    public function dataQualityFor(User $user): array
    {
        $visibleLibraryIds = $this->visibleLibraryIds($user);
        $rows = collect();

        foreach (self::CORE_FIELDS as $modelClass => $fields) {
            $modelClass::query()
                ->whereIn('library_id', $visibleLibraryIds)
                ->with('library:id,name,media_type')
                ->get()
                ->each(function (Model $item) use ($fields, $rows) {
                    $missing = collect($fields)->filter(fn (string $field) => $this->isEmptyValue($item->{$field}))->values()->all();

                    if ($missing !== []) {
                        $rows->push([...$this->itemSummary($item), 'missing_fields' => $missing]);
                    }
                });
        }

        return $rows->sortByDesc(fn (array $row) => count($row['missing_fields']))->values()->all();
    }

    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    /**
     * #74's "Top-Listen" idea — simple sorts over fields that already exist
     * but have no dedicated view anywhere today. price/disc_count are
     * compared across every media type that has the column (both share the
     * same unit — a price, a disc count — regardless of media type);
     * runtime is kept separate per type since MediaCd::runtime_seconds and
     * MediaDvdBluray::runtime_minutes aren't the same unit.
     *
     * @return array<string, array<int, array{value: mixed}>>
     */
    public function topListsFor(User $user): array
    {
        $visibleLibraryIds = $this->visibleLibraryIds($user);

        return [
            'most_expensive' => $this->topAcrossTypes($visibleLibraryIds, 'price', 'desc'),
            'cheapest' => $this->topAcrossTypes($visibleLibraryIds, 'price', 'asc'),
            'most_pages' => $this->topForModel(MediaBook::class, $visibleLibraryIds, 'page_count', 'desc'),
            'longest_cd_runtime' => $this->topForModel(MediaCd::class, $visibleLibraryIds, 'runtime_seconds', 'desc'),
            'shortest_cd_runtime' => $this->topForModel(MediaCd::class, $visibleLibraryIds, 'runtime_seconds', 'asc'),
            'longest_dvd_runtime' => $this->topForModel(MediaDvdBluray::class, $visibleLibraryIds, 'runtime_minutes', 'desc'),
            'shortest_dvd_runtime' => $this->topForModel(MediaDvdBluray::class, $visibleLibraryIds, 'runtime_minutes', 'asc'),
            'highest_disc_count' => $this->topAcrossTypes($visibleLibraryIds, 'disc_count', 'desc', [MediaCd::class, MediaDvdBluray::class]),
        ];
    }

    /** @return array<int, array{value: mixed}> */
    private function topForModel(string $modelClass, Collection $visibleLibraryIds, string $column, string $direction, int $limit = self::TOP_N): array
    {
        return $modelClass::query()
            ->whereIn('library_id', $visibleLibraryIds)
            ->whereNotNull($column)
            ->with('library:id,name,media_type')
            ->orderBy($column, $direction)
            ->limit($limit)
            ->get()
            ->map(fn (Model $item) => [...$this->itemSummary($item), 'value' => $item->{$column}])
            ->all();
    }

    /**
     * @param  class-string<Model>[]  $modelClasses
     * @return array<int, array{value: mixed}>
     */
    private function topAcrossTypes(Collection $visibleLibraryIds, string $column, string $direction, array $modelClasses = self::MEDIA_MODEL_CLASSES, int $limit = self::TOP_N): array
    {
        $items = collect();
        foreach ($modelClasses as $modelClass) {
            $items = $items->merge(
                $modelClass::query()->whereIn('library_id', $visibleLibraryIds)->whereNotNull($column)->with('library:id,name,media_type')->get()
            );
        }

        $sorted = $direction === 'desc' ? $items->sortByDesc($column) : $items->sortBy($column);

        return $sorted->take($limit)->map(fn (Model $item) => [...$this->itemSummary($item), 'value' => $item->{$column}])->values()->all();
    }

    /**
     * #74's "Neueste Zugänge" idea — a chronological list of concrete items
     * across every visible library, the item-level complement to
     * StatisticsService::valueHistoryFor()'s aggregated growth curve (#30),
     * which shows numbers over time but not which items they were. Each
     * model is pre-limited before merging so this stays bounded regardless
     * of how many libraries/items are visible.
     *
     * @return array<int, array>
     */
    public function recentAdditionsFor(User $user, int $limit = 50): array
    {
        $visibleLibraryIds = $this->visibleLibraryIds($user);
        $items = collect();

        foreach (self::MEDIA_MODEL_CLASSES as $modelClass) {
            $items = $items->merge(
                $modelClass::query()
                    ->whereIn('library_id', $visibleLibraryIds)
                    ->with('library:id,name,media_type')
                    ->orderByDesc('created_at')
                    ->limit($limit)
                    ->get()
            );
        }

        return $items->sortByDesc(fn (Model $item) => $item->created_at)
            ->take($limit)
            ->map(fn (Model $item) => $this->itemSummary($item))
            ->values()
            ->all();
    }

    /**
     * #74's first "größerer Aufwand" idea: how each visible item was
     * captured (capture_method) and, if metadata-driven, which provider(s)
     * supplied it (metadata_provider) — see the migration that added both
     * columns. Item-level by definition ("je Item", per the issue text),
     * plus two summary breakdowns so the per-item list isn't the only way to
     * read it.
     *
     * @return array{items: array, by_capture_method: array<string, int>, by_metadata_provider: array<string, int>}
     */
    public function captureSourceFor(User $user): array
    {
        $visibleLibraryIds = $this->visibleLibraryIds($user);
        $items = collect();

        foreach (self::MEDIA_MODEL_CLASSES as $modelClass) {
            $items = $items->merge(
                $modelClass::query()->whereIn('library_id', $visibleLibraryIds)->with(['library:id,name,media_type', 'capturedBy:id,name'])->get()
            );
        }

        $rows = $items->sortByDesc(fn (Model $item) => $item->created_at)->values()->map(fn (Model $item) => [
            ...$this->itemSummary($item),
            'capture_method' => $item->capture_method,
            'metadata_provider' => $item->metadata_provider,
            'captured_by' => $item->capturedBy?->name,
        ]);

        return [
            'items' => $rows->all(),
            'by_capture_method' => $rows->countBy(fn (array $row) => $row['capture_method'] ?? 'unknown')->all(),
            // Comma-separated (see the migration's docblock) — split the
            // same way StatisticsService::multiValueDistribution() already
            // splits MediaDvdBluray::languages.
            //
            // GitHub issue #149: a stored value is the full, media-type-
            // scoped provider_key (e.g. "book.amazon"/"cd.amazon"/
            // "dvd_bluray.amazon" — the same key MetadataCandidate::
            // providerKey/provider()->key() produce), so a provider that
            // exists for more than one media type used to get counted as
            // several distinct entries here, one per media type, rather
            // than a single combined total. Stripped down to "everything
            // after the first dot" before counting — the exact same
            // normalization frontend/src/pages/capture/
            // MetadataMergeReview.tsx's formatProviderKey() already
            // applies when turning a key into its display label, just
            // applied here at count time instead of render time so the
            // *count* itself reflects "this provider" rather than "this
            // provider for this one media type".
            'by_metadata_provider' => $rows->pluck('metadata_provider')
                ->filter()
                ->flatMap(fn (string $value) => explode(',', $value))
                ->map(fn (string $key) => str_contains($key, '.') ? substr($key, strpos($key, '.') + 1) : $key)
                ->countBy()
                ->sortDesc()
                ->all(),
        ];
    }

    private function visibleLibraryIds(User $user): Collection
    {
        return $this->accessService->visibleLibrariesQuery($user)->pluck('id');
    }

    /**
     * "Freigabe-/Sharing-Übersicht" (GitHub issue #74, moved here from
     * StatisticsService by GitHub issue #103 — see this class's own
     * docblock for why) — how many libraries are shared, with how many
     * users, on which access level.
     *
     * Restricted to libraries the requesting user can manage
     * (LibraryAccessService::canWrite() — owner or admin), not merely read:
     * LibraryController::show() already treats a library's share list as
     * management-sensitive, only ever loading `shares` for a canWrite()
     * caller ("no business learning who else it's shared with" otherwise)
     * — this mirrors that same restriction rather than exposing it more
     * broadly than the rest of the app already does.
     *
     * @return array<int, array{library_id:int, library_name:string, media_type:string, is_shared:bool, share_count:int, shares:array}>
     */
    public function sharingFor(User $user): array
    {
        return $this->accessService->visibleLibrariesQuery($user)
            ->with('shares.user:id,name')
            ->get()
            ->filter(fn (Library $library) => $this->accessService->canWrite($user, $library))
            ->map(fn (Library $library) => [
                'library_id' => $library->id,
                'library_name' => $library->name,
                'media_type' => $library->media_type,
                'is_shared' => $library->shares->isNotEmpty(),
                'share_count' => $library->shares->count(),
                'shares' => $library->shares->map(fn (LibraryShare $share) => [
                    'scope' => $share->scope,
                    'access_level' => $share->access_level,
                    'user_name' => $share->user?->name,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * "Aktivität je Benutzer" (GitHub issue #74's second "größerer Aufwand"
     * idea, moved here from StatisticsService by GitHub issue #103 — see
     * this class's own docblock for why) — how many items each user has
     * captured, and when they last did so, across every visible library.
     * Made possible by MediaBook/MediaCd/MediaDvdBluray::captured_by_user_id
     * (see the migration that added it) — a field this app never had
     * before #74, so an item captured before this feature shipped groups
     * under `user_id: null` ("unknown") rather than being silently
     * excluded.
     *
     * @return array<int, array{user_id: ?int, user_name: ?string, item_count: int, last_captured_at: ?string}>
     */
    public function userActivityFor(User $user): array
    {
        $visibleLibraryIds = $this->accessService->visibleLibrariesQuery($user)->pluck('id');
        $items = collect();

        foreach (self::MEDIA_MODEL_CLASSES as $modelClass) {
            $items = $items->merge(
                $modelClass::query()
                    ->whereIn('library_id', $visibleLibraryIds)
                    ->with('capturedBy:id,name')
                    ->get(['id', 'captured_by_user_id', 'created_at'])
            );
        }

        return $items->groupBy('captured_by_user_id')
            ->map(function (Collection $group, $userId) {
                /** @var Model $first */
                $first = $group->first();

                return [
                    'user_id' => $userId === '' || $userId === null ? null : (int) $userId,
                    'user_name' => $first->capturedBy?->name,
                    'item_count' => $group->count(),
                    'last_captured_at' => $group->pluck('created_at')->filter()->sort()->last()?->toIso8601String(),
                ];
            })
            ->sortByDesc('item_count')
            ->values()
            ->all();
    }

    /** @return array{id: int, title: string, ean: string, library_id: int, library_name: string, media_type: string, price: mixed, currency: ?string, created_at: ?string} */
    private function itemSummary(Model $item): array
    {
        /** @var Library $library */
        $library = $item->library;

        return [
            'id' => $item->id,
            'title' => $item->title,
            'ean' => $item->ean,
            'library_id' => $item->library_id,
            'library_name' => $library->name,
            'media_type' => $library->media_type,
            'price' => $item->price,
            'currency' => $item->currency,
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }
}
