<?php

namespace App\Domain\Statistics;

use App\Domain\Libraries\LibraryAccessService;
use App\Models\Library;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Bestand statistics (briefing 14.), scoped through LibraryAccessService so
 * a user only sees numbers for libraries they can read. Chapter 14 leaves
 * the exact scope open ("noch zu konkretisieren") — the breakdowns below
 * cover the "denkbar" examples explicitly listed (Genre, Sprache,
 * Erscheinungsjahr, Herausgeber/Künstler/Regisseur); "Zeitlicher Zuwachs
 * des Bestands" (growth over time) is a different kind of feature (needs
 * historical snapshots, not just aggregating current state) and isn't
 * covered here — see GitHub issue #7, scoped to Genre/Sprache/Jahr.
 */
class StatisticsService
{
    public function __construct(private readonly LibraryAccessService $accessService) {}

    public function overviewFor(User $user): array
    {
        $libraries = $this->accessService->visibleLibrariesQuery($user)->withCount([
            'mediaBooks', 'mediaCds', 'mediaDvdBlurays',
        ])->get();

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
            'distributions' => $this->distributionsFor($library),
        ])->all();
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
