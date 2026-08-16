<?php

namespace App\Domain\Statistics;

use App\Domain\Libraries\LibraryAccessService;
use App\Models\Library;
use App\Models\LibraryValueSnapshot;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Bestand statistics (briefing 14.), scoped through LibraryAccessService so
 * a user only sees numbers for libraries they can read. Chapter 14 leaves
 * the exact scope open ("noch zu konkretisieren") — the breakdowns below
 * cover the "denkbar" examples explicitly listed (Genre, Sprache,
 * Erscheinungsjahr, Herausgeber/Künstler/Regisseur, GitHub issue #7).
 * "Zeitlicher Zuwachs des Bestands" (growth over time, GitHub issue #30) is
 * covered separately by snapshotAll()/valueHistoryFor() below, since it
 * needs historical snapshots rather than just aggregating current state.
 */
class StatisticsService
{
    public function __construct(private readonly LibraryAccessService $accessService) {}

    public function overviewFor(User $user): array
    {
        $libraries = $this->accessService->visibleLibrariesQuery($user)->withCount([
            'mediaBooks', 'mediaCds', 'mediaDvdBlurays',
        ])->get();

        // GitHub issue #62: `total_value` still sums `price` as a bare
        // number regardless of `currency` (#58) — a real gap this doesn't
        // actually close (a library with genuinely mixed currencies still
        // gets a meaningless sum), only makes visible: `currency_mismatch`
        // flags a library as having at least one item whose currency
        // disagrees with the admin-configured default
        // (AdminSettingsController::updateStatistics()), so a total that
        // might be wrong is at least distinguishable from one that
        // definitely isn't. Never true when no default is configured at
        // all — there's nothing to compare against yet.
        $defaultCurrency = SystemSetting::get('statistics.default_currency');

        return $libraries->map(fn (Library $library) => [
            'library_id' => $library->id,
            'library_name' => $library->name,
            'media_type' => $library->media_type,
            'item_count' => match ($library->media_type) {
                'book' => $library->media_books_count,
                'cd' => $library->media_cds_count,
                'dvd_bluray' => $library->media_dvd_blurays_count,
            },
            'total_value' => $library->mediaItems()->sum('price'),
            'currency_mismatch' => $defaultCurrency !== null
                && $library->mediaItems()->whereNotNull('currency')->where('currency', '!=', $defaultCurrency)->exists(),
            'distributions' => $this->distributionsFor($library),
        ])->all();
    }

    /**
     * Writes today's item_count/total_value for every library in the
     * system — not scoped to a particular user, unlike every other method
     * here, since this is the write side of a scheduled job
     * (SnapshotLibraryValuesCommand, registered daily in
     * routes/console.php), not a per-request read. One row per library per
     * calendar day; updateOrCreate() makes re-running it the same day (a
     * crash, a manual `php artisan medinv:snapshot-library-values`)
     * overwrite rather than duplicate today's row.
     */
    public function snapshotAll(): void
    {
        $today = Carbon::today()->toDateString();

        foreach (Library::all() as $library) {
            LibraryValueSnapshot::query()->updateOrCreate(
                ['library_id' => $library->id, 'snapshot_date' => $today],
                [
                    'item_count' => $library->mediaItems()->count(),
                    'total_value' => $library->mediaItems()->sum('price'),
                ]
            );
        }
    }

    /**
     * Combines the two data sources described in GitHub issue #30: real
     * daily snapshots from snapshotAll() above (accurate, but only exist
     * from whenever this feature was first deployed onward) and, for the
     * period before that, an approximation derived from each item's
     * created_at — see the creating migration's docblock for that
     * tradeoff. The cutover point is the earliest snapshot_date that
     * exists anywhere in the table: snapshotAll() snapshots every library
     * in one run, so every library's first real row lands on the very same
     * calendar day, and a single global cutover is enough (no need to
     * track one per library). Scoped through LibraryAccessService like
     * overviewFor(), so an unshared library contributes nothing here
     * either.
     *
     * @return array{libraries: array<int, array{library_id:int, library_name:string, series: array}>, accumulated: array{series: array}, cutover_date: ?string}
     */
    public function valueHistoryFor(User $user): array
    {
        $libraries = $this->accessService->visibleLibrariesQuery($user)->get();
        $cutoverDate = LibraryValueSnapshot::query()->min('snapshot_date');

        $perLibrary = $libraries->map(fn (Library $library) => [
            'library_id' => $library->id,
            'library_name' => $library->name,
            'series' => $this->seriesFor($library, $cutoverDate),
        ])->all();

        return [
            'libraries' => $perLibrary,
            'accumulated' => ['series' => $this->accumulate(array_column($perLibrary, 'series'))],
            'cutover_date' => $cutoverDate,
        ];
    }

    /**
     * One library's combined series: the created_at-derived estimate for
     * everything strictly before $cutoverDate, followed by real snapshot
     * rows from $cutoverDate onward. A library created after $cutoverDate
     * simply has no estimated points (no items existed before a cutover
     * that postdates the library itself) and starts from whichever real
     * snapshot the daily job first found it in.
     *
     * @return array<int, array{date:string, item_count:int, total_value:float, source:string}>
     */
    private function seriesFor(Library $library, ?string $cutoverDate): array
    {
        $real = LibraryValueSnapshot::query()
            ->where('library_id', $library->id)
            ->when($cutoverDate, fn ($q) => $q->where('snapshot_date', '>=', $cutoverDate))
            ->orderBy('snapshot_date')
            ->get()
            ->map(fn (LibraryValueSnapshot $snapshot) => [
                'date' => $snapshot->snapshot_date->toDateString(),
                'item_count' => $snapshot->item_count,
                'total_value' => (float) $snapshot->total_value,
                'source' => 'snapshot',
            ])
            ->all();

        return [...$this->estimatedSeries($library, $cutoverDate), ...$real];
    }

    /**
     * A cumulative running total of item_count/total_value, one point per
     * distinct day an item was created (not one per calendar day — this
     * app's realistic data volumes make grouping in PHP over every item
     * the pragmatic choice, same tradeoff distributionsFor() already
     * makes). Deletions and price edits after the fact aren't reflected —
     * created_at only ever records when an item was captured, not the
     * historical bestand at an arbitrary point in the past.
     *
     * @return array<int, array{date:string, item_count:int, total_value:float, source:string}>
     */
    private function estimatedSeries(Library $library, ?string $cutoverDate): array
    {
        $items = $library->mediaItems()
            ->when($cutoverDate, fn ($q) => $q->whereDate('created_at', '<', $cutoverDate))
            ->orderBy('created_at')
            ->get(['created_at', 'price']);

        $runningCount = 0;
        $runningValue = 0.0;
        $series = [];

        foreach ($items->groupBy(fn ($item) => $item->created_at->toDateString()) as $date => $dayItems) {
            $runningCount += $dayItems->count();
            $runningValue += (float) $dayItems->sum('price');
            $series[] = [
                'date' => $date,
                'item_count' => $runningCount,
                'total_value' => round($runningValue, 2),
                'source' => 'estimated',
            ];
        }

        return $series;
    }

    /**
     * Sums every visible library's series into one accumulated curve, on
     * the union of dates across all of them — most libraries won't share
     * the same snapshot/estimate dates, so each library's value at a given
     * date is carried forward (step function) from its own latest point at
     * or before that date, not treated as 0 just because that exact date
     * isn't one of its own points.
     *
     * @param  array<int, array<int, array{date:string, item_count:int, total_value:float, source:string}>>  $allSeries
     * @return array<int, array{date:string, item_count:int, total_value:float}>
     */
    private function accumulate(array $allSeries): array
    {
        $dates = collect($allSeries)->flatten(1)->pluck('date')->unique()->sort()->values();

        return $dates->map(function (string $date) use ($allSeries) {
            $itemCount = 0;
            $totalValue = 0.0;

            foreach ($allSeries as $series) {
                $latest = collect($series)->last(fn (array $point) => $point['date'] <= $date);

                if ($latest) {
                    $itemCount += $latest['item_count'];
                    $totalValue += $latest['total_value'];
                }
            }

            return ['date' => $date, 'item_count' => $itemCount, 'total_value' => round($totalValue, 2)];
        })->values()->all();
    }

    /**
     * Which dimensions apply depends on the library's media type — only
     * MediaBook has a `genre` column at all, for instance (briefing 6.'s
     * fixed, non-overlapping per-type attribute sets, see CLAUDE.md).
     * Grouped in PHP rather than a per-database-backend SQL GROUP BY/date
     * function (sqlite/mariadb/pgsql all need to work, see CLAUDE.md's
     * fuzzy-search precedent for the identical tradeoff) — this app's
     * realistic data volumes (personal collections, not web-scale) make a
     * portable implementation the pragmatic choice for what's an
     * infrequent admin-facing report, not a hot path.
     *
     * @return array<string, array<int|string, int>>
     */
    private function distributionsFor(Library $library): array
    {
        return match ($library->media_type) {
            'book' => [
                'genre' => $this->valueDistribution($library, 'genre'),
                'language' => $this->valueDistribution($library, 'language'),
                'publisher' => $this->valueDistribution($library, 'publisher'),
                'year' => $this->dateYearDistribution($library, 'release_date'),
            ],
            'cd' => [
                'artist' => $this->valueDistribution($library, 'artist'),
                'year' => $this->dateYearDistribution($library, 'release_date'),
            ],
            'dvd_bluray' => [
                'director' => $this->valueDistribution($library, 'director'),
                // `languages` holds a comma-separated list (e.g. "Deutsch,
                // Englisch") — split so each language is counted on its own
                // instead of the whole combination becoming one category.
                'language' => $this->multiValueDistribution($library, 'languages'),
                // MediaDvdBluray carries a dedicated `production_year` column
                // alongside `release_date` — that's the more direct source
                // for "which year" here, unlike book/CD, which only have a
                // release date to derive a year from.
                'year' => $this->integerYearDistribution($library, 'production_year'),
            ],
        };
    }

    /**
     * Items with no value for this column are excluded rather than counted
     * as an "unknown" bucket — this reports the distribution among items
     * that actually have the attribute set.
     *
     * @return array<string, int> Value => count, most common first.
     */
    private function valueDistribution(Library $library, string $column): array
    {
        return $library->mediaItems()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->pluck($column)
            ->countBy()
            ->sortDesc()
            ->all();
    }

    /** @return array<string, int> Value => count, most common first. */
    private function multiValueDistribution(Library $library, string $column): array
    {
        return $library->mediaItems()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->pluck($column)
            ->flatMap(fn (string $value) => array_map('trim', explode(',', $value)))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->all();
    }

    /** @return array<int, int> Year => count, oldest first. */
    private function dateYearDistribution(Library $library, string $column): array
    {
        return $library->mediaItems()
            ->whereNotNull($column)
            ->pluck($column)
            ->map(fn (Carbon $date) => $date->year)
            ->countBy()
            ->sortKeys()
            ->all();
    }

    /** @return array<int, int> Year => count, oldest first. */
    private function integerYearDistribution(Library $library, string $column): array
    {
        return $library->mediaItems()
            ->whereNotNull($column)
            ->pluck($column)
            ->map(fn ($year) => (int) $year)
            ->countBy()
            ->sortKeys()
            ->all();
    }
}
