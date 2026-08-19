<?php

namespace App\Domain\Reports;

use App\Domain\Libraries\LibraryAccessService;
use App\Models\Library;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\MediaDvdBluray;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * "Auswertungen" (GitHub issue #74) — a new, standalone domain module
 * (briefing 10.'s "ein Namensraum je Fachmodul") deliberately separate from
 * App\Domain\Statistics: per issue #74's own clarifying comment, a
 * Statistik is charts/aggregated sums with no reference to individual
 * items, while an Auswertung is a *table of concrete media items* — every
 * method below returns identifiable items (id/title/ean/library), never
 * just a count. "Freigabe-/Sharing-Übersicht" and "Aktivität je Benutzer"
 * (per-user counts, no item list) went to StatisticsService instead for
 * the same reason; only the item-level ideas from #74 live here.
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
            'by_metadata_provider' => $rows->pluck('metadata_provider')
                ->filter()
                ->flatMap(fn (string $value) => explode(',', $value))
                ->countBy()
                ->sortDesc()
                ->all(),
        ];
    }

    private function visibleLibraryIds(User $user): Collection
    {
        return $this->accessService->visibleLibrariesQuery($user)->pluck('id');
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
