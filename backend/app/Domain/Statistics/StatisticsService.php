<?php

namespace App\Domain\Statistics;

use App\Domain\Libraries\LibraryAccessService;
use App\Models\Library;
use App\Models\User;

/**
 * Bestand statistics (briefing 14.), scoped through LibraryAccessService so
 * a user only sees numbers for libraries they can read. Chapter 14 leaves
 * the exact scope open ("noch zu konkretisieren") — the breakdowns below
 * cover the "denkbar" examples explicitly listed; extend per-media-type as
 * needed.
 */
class StatisticsService
{
    public function __construct(private readonly LibraryAccessService $accessService) {}

    public function overviewFor(User $user): array
    {
        $libraries = $this->accessService->visibleLibrariesQuery($user)->withCount([
            'mediaBooks', 'mediaCds', 'mediaDvdBlurays',
        ])->get();

        // TODO: genre/language/year/publisher-artist-director distributions
        // and growth-over-time (14.) — group-by queries per media type once
        // the exact chart/report shapes are decided with the frontend.
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
        ])->all();
    }
}
