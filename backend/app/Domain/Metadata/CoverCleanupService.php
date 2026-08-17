<?php

namespace App\Domain\Metadata;

use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\MediaDvdBluray;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Orphaned-cover-file sweep, run daily (routes/console.php,
 * `medinv:cleanup-covers`, CleanupOrphanedCoversCommand). Every cover-owning
 * write path already cleans up its own files immediately — uploading a new
 * cover deletes the old one, removing a cover deletes both its files,
 * deleting a media item deletes its cover+thumbnail (see
 * MediaItemController::uploadCover()/deleteCover()/destroy()) — but a
 * request that dies between steps (a crash, a killed container), a
 * restored backup that doesn't perfectly reconcile every file, or direct
 * database manipulation can still leave a file on the `local` disk under
 * covers/ that nothing in the database references anymore. This walks
 * every file actually on disk and deletes whichever ones no media item's
 * `cover_path` (or its derived thumbnail, see
 * CoverDownloadService::thumbnailPath()) points at — the disk should only
 * ever hold what the database still references.
 */
class CoverCleanupService
{
    private const DIR = 'covers';

    /**
     * A file this fresh is never deleted, no matter what referencedPaths()
     * says — CoverDownloadService::store() writes the cover (and its
     * thumbnail) to disk first and only afterwards does the calling
     * controller persist `cover_path` on the media item (MediaItemController::
     * uploadCover(), MetadataController::store()/reimport()'s $item->update()
     * calls, all *after* the download()/uploadFromFile() call that wrote the
     * file) — so there's a real, if normally brief, window where a
     * legitimate, about-to-be-referenced file sits on disk with nothing in
     * the database pointing at it yet. Without this grace period, a cleanup
     * run landing in that exact window (a slow request stalled during that
     * window, a container restart between the two steps, ...) would read
     * that as "orphaned" and delete a cover a user just captured. A file
     * this recently written is deferred to the next run instead, by which
     * point the reference has long since been saved (or, if the request
     * that wrote it genuinely never finished, it's a real orphan the next
     * run will still catch).
     */
    private const GRACE_PERIOD_MINUTES = 60;

    public function __construct(private readonly CoverDownloadService $coverDownloadService) {}

    /** @return int Number of orphaned files deleted. */
    public function cleanup(): int
    {
        $referenced = $this->referencedPaths();
        $onDisk = Storage::disk('local')->allFiles(self::DIR);
        $deleted = 0;
        $cutoff = now()->subMinutes(self::GRACE_PERIOD_MINUTES)->timestamp;

        foreach ($onDisk as $path) {
            if ($referenced->contains($path)) {
                continue;
            }

            // See GRACE_PERIOD_MINUTES's docblock — a very recently written
            // file might just be mid-capture, not actually orphaned yet.
            if (Storage::disk('local')->lastModified($path) > $cutoff) {
                continue;
            }

            Storage::disk('local')->delete($path);
            Log::info('Deleted orphaned cover file.', ['path' => $path]);
            $deleted++;
        }

        Log::info('Cover cleanup complete.', [
            'checked' => count($onDisk),
            'deleted' => $deleted,
        ]);

        return $deleted;
    }

    /** Every path a media item's cover_path (or its thumbnail) actually points at, across all three media types (briefing 6.). */
    private function referencedPaths(): Collection
    {
        return collect([MediaBook::class, MediaCd::class, MediaDvdBluray::class])
            ->flatMap(fn (string $model) => $model::query()->whereNotNull('cover_path')->pluck('cover_path'))
            ->flatMap(fn (string $path) => [$path, $this->coverDownloadService->thumbnailPath($path)]);
    }
}
