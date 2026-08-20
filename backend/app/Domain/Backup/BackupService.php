<?php

namespace App\Domain\Backup;

use App\Domain\ExportImport\ExportImportService;
use App\Models\Backup;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Backup creation, retention and restoration (briefing 9.2/9.3). A backup
 * is a zip containing manifest.json (the same structure ExportImportService
 * produces for a full ("alle") export — restoration therefore reuses
 * ExportImportService::importLibraries() and its conflict-resolution modes)
 * plus every cover image referenced by an item's `cover_path` (GitHub issue
 * #26: a backup without them silently loses covers on restore, since
 * cover_path only makes sense relative to *this* instance's `local` disk —
 * see CoverDownloadService).
 *
 * Storage location: storage/app/private/backups (see docker-compose.yml — mounted
 * as the `backups` volume from docs/medinv-briefing.md chapter 19, so
 * backups survive container recreation).
 */
class BackupService
{
    private const DISK = 'local';

    private const DIR = 'backups';

    public function __construct(private readonly ExportImportService $exportImportService) {}

    /**
     * `$reason` distinguishes which of the two independent automatic paths
     * actually created this backup — 'scheduled' (the admin-configured
     * interval, routes/console.php) or 'pre_update' (PreUpdateBackupCommand,
     * ahead of a pending migration). Always null for trigger='manual' — an
     * admin explicitly clicking "create backup now" already fully explains
     * itself, it doesn't need a separate reason label.
     */
    public function create(string $trigger = 'manual', ?string $intervalMode = null, ?string $reason = null): Backup
    {
        // includeUsers: true — a backup is a full snapshot of this instance (briefing
        // 9.2), unlike an ordinary admin-initiated library export (9.1), which never
        // carries user accounts/password hashes. See exportLibraries()'s docblock.
        $data = $this->exportImportService->exportLibraries(null, includeUsers: true);
        // SystemSetting::localNow(), not now() — GitHub issue #31: the filename
        // is the one place an admin actually reads this timestamp, so it should
        // reflect their configured display timezone, not always UTC.
        $filename = 'medinv-backup-'.SystemSetting::localNow()->format('Ymd-His').'.zip';
        $path = self::DIR.'/'.$filename;

        $tmpJson = tempnam(sys_get_temp_dir(), 'medinv-backup');
        file_put_contents($tmpJson, json_encode($data, JSON_PRETTY_PRINT));

        $zip = new ZipArchive;
        $absolutePath = Storage::disk(self::DISK)->path($path);
        Storage::disk(self::DISK)->makeDirectory(self::DIR);
        $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($tmpJson, 'manifest.json');
        $this->exportImportService->addCoverFilesToZip($zip, $data);
        $zip->close();
        unlink($tmpJson);

        $attributes = [
            'filename' => $filename,
            'size_bytes' => Storage::disk(self::DISK)->size($path),
            'trigger' => $trigger,
            'interval_mode' => $intervalMode,
            'status' => 'completed',
        ];

        // PreUpdateBackupCommand deliberately calls create() *before*
        // `php artisan migrate --force` runs (docker/entrypoint.sh) — the
        // whole point is a safety-net backup ahead of whatever the pending
        // migrations are about to change. That includes the migration that
        // added this very `reason` column: on the one-time upgrade across
        // that boundary, the column genuinely doesn't exist yet at the
        // exact moment this runs, and an unconditional 'reason' => $reason
        // here made the safety-net backup itself fail with "no such
        // column" — confirmed live by reproducing the exact scenario an
        // existing install upgrades through. Same defensive shape
        // PreUpdateBackupCommand::handle() already uses via
        // Schema::hasTable('migrations') for the analogous "are we
        // mid-update" concern. Any future column added here needs the same
        // guard for the same reason.
        if (Schema::hasColumn('backups', 'reason')) {
            $attributes['reason'] = $reason;
        }

        $backup = Backup::query()->create($attributes);

        // Every backup previously went entirely unlogged — for an automatic
        // one (the scheduled interval, or PreUpdateBackupCommand's pre-update
        // safety net) this is often the only record it ever happened at all,
        // since nothing else surfaces it to anyone actively watching.
        Log::info('Backup created', [
            'filename' => $filename,
            'size_bytes' => $attributes['size_bytes'],
            'trigger' => $trigger,
            'reason' => $reason,
            'interval_mode' => $intervalMode,
        ]);

        $this->prune();

        return $backup;
    }

    /**
     * GitHub-reported gap: BackupController::index() (the admin UI's backup
     * list) and prune() (retention) both work purely off this table — never
     * the filesystem — so a .zip that ends up in storage/app/private/backups
     * without a matching `backups` row is simultaneously invisible in the UI
     * *and* permanently exempt from automatic cleanup, silently taking up
     * disk space forever. This can happen whenever the two fall out of sync
     * with each other: most concretely, GitHub issue #25's now-fixed bug,
     * where every container recreation reset the sqlite database to empty
     * while the separately-mounted `backups` volume (never affected by that
     * bug) kept every file ever written to it — but the same mismatch could
     * just as easily follow a manual `DB::table('backups')->truncate()`, a
     * restored database from an older point in time, or a switched DB
     * connection/backend. Recreates a row for every such orphaned file, so
     * `index()` calling this first makes the admin UI self-heal the moment
     * anyone opens the Backups page, without needing a separate admin
     * action or waiting on a schedule — this is purely additive (only ever
     * inserts, never deletes/modifies an existing row), so it's safe to run
     * on every page load.
     *
     * Reconciled rows are always trigger: 'manual', regardless of how the
     * file actually came to exist — genuinely unknowable at this point, and
     * 'manual' is prune()'s own existing exemption from automatic deletion
     * (see its docblock): a real automatic backup lingering a little longer
     * than intended is a far smaller problem than a genuine admin-made one
     * getting swept away by a retention policy that never actually chose to
     * keep it. `created_at`/`updated_at` are backdated to the file's own
     * on-disk mtime (forceFill(), since timestamps aren't in Backup's
     * #[Fillable(...)] list) rather than "now", so the UI shows when the
     * backup was actually made, not when it happened to be rediscovered.
     *
     * @return int Number of rows reconciled.
     */
    public function reconcileWithDisk(): int
    {
        $knownFilenames = Backup::query()->pluck('filename')->all();
        $onDisk = collect(Storage::disk(self::DISK)->files(self::DIR))
            ->filter(fn (string $path) => str_ends_with($path, '.zip'));

        $reconciled = 0;

        foreach ($onDisk as $path) {
            $filename = basename($path);

            if (in_array($filename, $knownFilenames, true)) {
                continue;
            }

            $mtime = Carbon::createFromTimestamp(Storage::disk(self::DISK)->lastModified($path));

            $backup = new Backup;
            $backup->forceFill([
                'filename' => $filename,
                'size_bytes' => Storage::disk(self::DISK)->size($path),
                'trigger' => 'manual',
                'status' => 'completed',
                'created_at' => $mtime,
                'updated_at' => $mtime,
            ])->save();

            $reconciled++;
        }

        if ($reconciled > 0) {
            Log::info('Reconciled orphaned backup file(s) on disk into the database.', ['count' => $reconciled]);
        }

        return $reconciled;
    }

    /**
     * Resolves the admin-configured backup.interval_mode (briefing 9.2)
     * into an actual cron expression for the Laravel scheduler
     * (routes/console.php). The three "Einfacher Modus" choices don't
     * carry their own time of day in the spec, so a single fixed,
     * low-traffic hour is used for all of them; "Experten-Modus" uses the
     * admin's own backup.cron_expression verbatim.
     */
    public function scheduledBackupCronExpression(): string
    {
        return match (SystemSetting::get('backup.interval_mode', 'daily')) {
            'daily' => '0 3 * * *',
            'weekly' => '0 3 * * 0',
            'monthly' => '0 3 1 * *',
            'cron' => SystemSetting::get('backup.cron_expression') ?: '0 3 * * *',
            default => '0 3 * * *',
        };
    }

    public function download(Backup $backup): string
    {
        return Storage::disk(self::DISK)->path(self::DIR.'/'.$backup->filename);
    }

    public function delete(Backup $backup): void
    {
        Storage::disk(self::DISK)->delete(self::DIR.'/'.$backup->filename);
        $backup->delete();
    }

    /**
     * Applies the retention defaults from briefing 9.2 (overridable via
     * system_settings): backups beyond the configured count, *or* older
     * than the configured max age, are deleted automatically — but only
     * one of the two criteria is ever actually active at a time
     * (`backup.retention_mode`, 'count' or 'age'), not both simultaneously.
     * Briefing 9.2 itself literally says "das eingestellte Alter
     * überschreiten ODER über die eingestellte Anzahl hinausgehen", i.e.
     * both rules applying in parallel — this is a deliberate admin-driven
     * deviation from that: having both editable and both silently in
     * effect at once was reported as confusing rather than useful, since
     * whichever rule is stricter always wins invisibly. The retention_count/
     * retention_max_age_days values themselves are unchanged (still stored,
     * still fall back to the interval-mode default below when unset) —
     * only which *one* of them prune() actually consults changed.
     *
     * Manual backups (trigger='manual', an admin explicitly clicking
     * "Create backup now") are deliberately exempt from this entirely —
     * an admin who takes a backup on purpose (e.g. right before a risky
     * change) shouldn't have it silently swept away by the automatic
     * backup schedule's *own* unrelated retention policy, nor have it eat
     * into the count/age budget that policy is meant to apply to.
     * Automatic backups (trigger='automatic' — both the scheduled ones
     * from routes/console.php and PreUpdateBackupCommand's pre-update
     * safety net, neither of which an admin asked for by name) are the
     * only ones this ever deletes.
     */
    public function prune(): void
    {
        $intervalMode = SystemSetting::get('backup.interval_mode', 'daily');

        [$defaultCount, $defaultMaxAgeDays] = match ($intervalMode) {
            'daily' => [7, 7],
            'weekly' => [4, 30],
            'monthly' => [12, 365],
            'cron' => [10, 182],
            default => [7, 7],
        };

        $automatic = Backup::query()->where('trigger', '!=', 'manual');

        $toPrune = match (SystemSetting::get('backup.retention_mode', 'count')) {
            'age' => (clone $automatic)->where(
                'created_at', '<', now()->subDays((int) SystemSetting::get('backup.retention_max_age_days', $defaultMaxAgeDays))
            )->get(),
            default => (clone $automatic)->orderByDesc('created_at')->get()
                ->slice((int) SystemSetting::get('backup.retention_count', $defaultCount)),
        };

        if ($toPrune->isEmpty()) {
            return;
        }

        // Only logged when something was actually pruned — same "don't log a
        // no-op" reasoning as the scheduler noise fix (routes/console.php's
        // ->when() filters): prune() runs after every single backup, so an
        // unconditional log line here would fire just as often as backup
        // creation itself, almost always to say nothing happened.
        Log::info('Backups pruned', ['count' => $toPrune->count(), 'filenames' => $toPrune->pluck('filename')->all()]);

        $toPrune->each(fn (Backup $b) => $this->delete($b));
    }

    /**
     * Restores a backup (briefing 9.3): reads manifest.json back out of the
     * zip into the same array shape exportLibraries() produces, then hands
     * it to ExportImportService::importLibraries(), which already carries
     * the conflict-resolution logic (rename/merge/overwrite/skip/cancel)
     * shared with ordinary instance-to-instance import (9.1). Both trigger
     * paths from 9.3 use this: BackupController::restore() (admin UI, with
     * $conflictResolutions/$restoreSettings/$restoreShares chosen
     * interactively per request) and Console\Commands\RestoreBackupOnBoot
     * (MEDINV_RESTOREBACKUP at container start, unattended).
     */
    public function restore(Backup $backup, User $importingAs, array $conflictResolutions = [], bool $restoreSettings = false, bool $restoreShares = false): array
    {
        $path = Storage::disk(self::DISK)->path(self::DIR.'/'.$backup->filename);

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException("Could not open backup archive: {$backup->filename}");
        }

        $manifest = $zip->getFromName('manifest.json');

        if ($manifest === false) {
            $zip->close();

            throw new \RuntimeException("Backup archive is missing manifest.json: {$backup->filename}");
        }

        $data = json_decode($manifest, true);

        if (! is_array($data)) {
            $zip->close();

            throw new \RuntimeException("Backup archive contains an invalid manifest.json: {$backup->filename}");
        }

        // Before the items that reference them are (re-)created below — see
        // ExportImportService::restoreCoverFilesFromZip()'s docblock.
        $this->exportImportService->restoreCoverFilesFromZip($zip);
        $zip->close();

        $result = $this->exportImportService->importLibraries($data, $importingAs, $conflictResolutions, $restoreSettings, $restoreShares);

        // Restoring overwrites/merges existing data — worth a clear record of
        // who triggered it (importingAs — note this is *not* necessarily an
        // HTTP-request actor: RestoreBackupOnBoot passes the seeded admin
        // account since MEDINV_RESTOREBACKUP runs unattended at container
        // start) and what it actually did, not just that some restore
        // happened.
        Log::info('Backup restored', [
            'filename' => $backup->filename,
            'actor_id' => $importingAs->id,
            'restore_settings' => $restoreSettings,
            'restore_shares' => $restoreShares,
            'created' => count($result['created'] ?? []),
            'merged' => count($result['merged'] ?? []),
            'overwritten' => count($result['overwritten'] ?? []),
            'skipped' => count($result['skipped'] ?? []),
            'settings_restored' => $result['settings_restored'] ?? false,
            'users_restored' => count($result['users_restored'] ?? []),
            'plugins_restored' => count($result['plugins_restored'] ?? []),
            'shares_skipped' => $result['shares_skipped'] ?? 0,
            'saved_searches_restored' => $result['saved_searches_restored'] ?? 0,
            'saved_searches_skipped' => $result['saved_searches_skipped'] ?? 0,
        ]);

        return $result;
    }
}
