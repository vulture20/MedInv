<?php

namespace App\Console\Commands;

use App\Domain\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Schema;

/**
 * Run before `php artisan migrate --force` on every container start
 * (docker/entrypoint.sh), so a program update that ships new migrations —
 * i.e. a database schema change — always has a safety-net backup taken
 * immediately beforehand. This is the concrete mechanism behind "Datenbank
 * vorbereiten, dass Programmupdates und damit Datenbankänderungen möglich
 * sind": migrations themselves are already additive/idempotent (Laravel's
 * migration system), and this command adds the missing piece — an
 * automatic rollback point — without requiring any manual step from
 * whoever deploys the update.
 *
 * Deliberately a no-op on a fresh install (nothing to protect yet) and
 * when there's nothing new to migrate (nothing changed), so it doesn't
 * create a backup on every ordinary container restart — only ahead of an
 * actual schema change to an existing database.
 */
class PreUpdateBackupCommand extends Command
{
    protected $signature = 'medinv:pre-update-backup';

    protected $description = 'Create a backup before migrating, if this is an update to an already-initialized database';

    public function handle(BackupService $backupService, Migrator $migrator): int
    {
        // Schema::hasTable() already accounts for the MEDINV_DB_PREFIX (MEDINV_)
        // configured in config/database.php.
        if (! Schema::hasTable('migrations')) {
            $this->info('Fresh install (no migrations table yet) — skipping pre-update backup.');

            return self::SUCCESS;
        }

        $migrationFiles = $migrator->getMigrationFiles(database_path('migrations'));
        $pending = array_diff(array_keys($migrationFiles), $migrator->getRepository()->getRan());

        if (empty($pending)) {
            $this->info('No pending migrations — skipping pre-update backup.');

            return self::SUCCESS;
        }

        $this->info('Pending migrations detected on an existing database — creating a safety backup first.');
        $backup = $backupService->create(trigger: 'automatic');
        $this->info("Created {$backup->filename} before running migrations.");

        return self::SUCCESS;
    }
}
