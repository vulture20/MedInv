<?php

namespace App\Console\Commands;

use App\Domain\Metadata\CoverCleanupService;
use Illuminate\Console\Command;

/**
 * Daily orphaned-cover-file sweep, registered in routes/console.php. See
 * CoverCleanupService's docblock for why files can end up orphaned even
 * though every write path already tries to clean up after itself.
 */
class CleanupOrphanedCoversCommand extends Command
{
    protected $signature = 'medinv:cleanup-covers';

    protected $description = 'Delete cover/thumbnail files on disk that no media item references anymore';

    public function handle(CoverCleanupService $service): int
    {
        $deleted = $service->cleanup();
        $this->info("Cover cleanup complete: {$deleted} orphaned file(s) deleted.");

        return self::SUCCESS;
    }
}
