<?php

namespace App\Console\Commands;

use App\Domain\Statistics\StatisticsService;
use Illuminate\Console\Command;

/**
 * Daily item_count/total_value snapshot per library (briefing 14., GitHub
 * issue #30), registered in routes/console.php. Feeds
 * StatisticsService::valueHistoryFor()'s "real" data points, alongside a
 * created_at-derived approximation for the period before this feature
 * existed.
 */
class SnapshotLibraryValuesCommand extends Command
{
    protected $signature = 'medinv:snapshot-library-values';

    protected $description = "Record today's item count and total value for every library";

    public function handle(StatisticsService $service): int
    {
        $service->snapshotAll();
        $this->info('Library value snapshot complete.');

        return self::SUCCESS;
    }
}
