<?php

namespace App\Console\Commands;

use App\Domain\Security\BruteForceProtection;
use Illuminate\Console\Command;

/**
 * Daily failed-login-record sweep, registered in routes/console.php. See
 * BruteForceProtection::RETENTION_DAYS' docblock for why this exists —
 * clearFailures() only ever clears a given email's rows on that same
 * email's next successful login, leaving no removal path at all for a row
 * whose email never logs in successfully again.
 */
class CleanupLoginAttemptsCommand extends Command
{
    protected $signature = 'medinv:cleanup-login-attempts';

    protected $description = 'Delete failed-login records older than the retention period';

    public function handle(BruteForceProtection $bruteForceProtection): int
    {
        $deleted = $bruteForceProtection->pruneOldFailures();
        $this->info("Login attempt cleanup complete: {$deleted} record(s) deleted.");

        return self::SUCCESS;
    }
}
