<?php

namespace App\Console\Commands;

use App\Domain\Backup\BackupService;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * MEDINV_RESTOREBACKUP (briefing 9.3/16.): the unattended restore trigger,
 * run from docker/entrypoint.sh right after `migrate --force` on container
 * start — for automated deployments (e.g. resetting a demo/staging instance
 * to a known-good snapshot on every restart), as opposed to
 * BackupController::restore()'s interactive admin-UI path.
 *
 * Unattended means there's no admin present to choose a per-library
 * conflict-resolution (rename/merge/overwrite/skip/cancel, 9.3), so every
 * conflicting library is overwritten via importLibraries()'s `__default__`
 * sentinel, and system settings + user accounts + library shares (GitHub
 * issue #80) are restored too (restoreSettings: true, restoreShares: true)
 * — MEDINV_RESTOREBACKUP's whole purpose is bringing this instance to
 * exactly the backed-up state, not a partial library-only import. Acts as
 * the predefined/protected admin account (DatabaseSeeder always creates
 * exactly one), since no user is logged in at boot time.
 */
class RestoreBackupOnBoot extends Command
{
    protected $signature = 'medinv:restore-backup {name : Backup filename (MEDINV_RESTOREBACKUP), with or without the .zip extension}';

    protected $description = 'Restore a named backup at container start, overwriting conflicting libraries and restoring settings/users/shares';

    public function handle(BackupService $backupService): int
    {
        $name = $this->argument('name');
        $filename = str_ends_with($name, '.zip') ? $name : "{$name}.zip";

        $backup = Backup::query()->where('filename', $filename)->first();

        if (! $backup) {
            $message = "MEDINV_RESTOREBACKUP={$name}: no backup with that filename exists (see the admin backups list for valid filenames). Skipping restore.";
            $this->error($message);
            // Also Log::, not just console output — this is an unattended boot-time
            // config error nobody is necessarily watching supervisord's stdout for.
            Log::warning($message);

            return self::FAILURE;
        }

        $actingAs = User::query()->where('is_protected', true)->first()
            ?? User::query()->where('level', 'admin')->orderBy('id')->first();

        if (! $actingAs) {
            $message = 'MEDINV_RESTOREBACKUP: no admin account exists yet to perform the restore as. Skipping restore.';
            $this->error($message);
            Log::warning($message);

            return self::FAILURE;
        }

        $this->info("Restoring backup {$backup->filename}...");

        $result = $backupService->restore(
            $backup,
            $actingAs,
            conflictResolutions: ['__default__' => 'overwrite'],
            restoreSettings: true,
            restoreShares: true,
        );

        $this->info(sprintf(
            'Restore complete: %d created, %d overwritten, %d merged, %d skipped, %d user(s) restored, settings restored: %s, %d share(s) skipped (no matching account).',
            count($result['created']),
            count($result['overwritten']),
            count($result['merged']),
            count($result['skipped']),
            count($result['users_restored']),
            $result['settings_restored'] ? 'yes' : 'no',
            $result['shares_skipped'],
        ));

        return self::SUCCESS;
    }
}
