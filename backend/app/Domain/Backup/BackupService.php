<?php

namespace App\Domain\Backup;

use App\Domain\ExportImport\ExportImportService;
use App\Models\Backup;
use App\Models\SystemSetting;
use App\Models\User;
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

    /** Must match CoverDownloadService::DIR — the prefix every item's cover_path is stored under on the `local` disk. */
    private const COVERS_DIR = 'covers';

    public function __construct(private readonly ExportImportService $exportImportService) {}

    public function create(string $trigger = 'manual', ?string $intervalMode = null): Backup
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
        $this->addCoverFiles($zip, $data);
        $zip->close();
        unlink($tmpJson);

        $backup = Backup::query()->create([
            'filename' => $filename,
            'size_bytes' => Storage::disk(self::DISK)->size($path),
            'trigger' => $trigger,
            'interval_mode' => $intervalMode,
            'status' => 'completed',
        ]);

        $this->prune();

        return $backup;
    }

    /**
     * Adds every cover image referenced anywhere in the export under its own
     * cover_path (already relative to the `local` disk, e.g.
     * `covers/book/1234-AbCdEfGh.jpg`, see CoverDownloadService) — using that
     * same relative path as the zip entry name so restoreCoverFiles() can
     * write it straight back without any translation table. Best-effort per
     * file: a cover_path whose file is already gone (e.g. deleted by hand
     * outside the app) is skipped rather than failing the whole backup, the
     * same trade-off CoverDownloadService itself makes for a failed download.
     */
    private function addCoverFiles(ZipArchive $zip, array $data): void
    {
        $coverPaths = collect($data['libraries'] ?? [])
            ->flatMap(fn (array $library) => collect($library['items'] ?? [])->pluck('cover_path'))
            ->filter()
            ->unique();

        foreach ($coverPaths as $coverPath) {
            if (Storage::disk(self::DISK)->exists($coverPath)) {
                $zip->addFile(Storage::disk(self::DISK)->path($coverPath), $coverPath);
            }
        }
    }

    /**
     * Writes every `covers/...` entry in the archive back onto the `local`
     * disk at its original relative path, before importLibraries() recreates
     * the items that reference them (restore() calls this first) — so a
     * cover is already in place by the time an item pointing at it exists,
     * and MediaItemController::cover() doesn't 404 for it post-restore.
     * Restores every cover present in the zip regardless of which items
     * conflict-resolution ends up actually (re-)creating — simpler and more
     * robust than correlating the two, at the cost of occasionally leaving
     * an unreferenced file behind for a library that was skipped, no worse
     * than any other orphaned-cover case.
     */
    private function restoreCoverFiles(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name === false || ! str_starts_with($name, self::COVERS_DIR.'/')) {
                continue;
            }

            $contents = $zip->getFromIndex($i);

            if ($contents !== false) {
                Storage::disk(self::DISK)->put($name, $contents);
            }
        }
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
     * system_settings): backups beyond the configured count or older than
     * the configured max age are deleted automatically. Manual backups
     * (trigger='manual', an admin explicitly clicking "Create backup now")
     * are deliberately exempt from both rules and from the count itself —
     * an admin who takes a backup on purpose (e.g. right before a risky
     * change) shouldn't have it silently swept away by the automatic
     * backup schedule's *own* unrelated retention policy, nor have it
     * eat into the number of automatic backups that policy is meant to
     * keep. Automatic backups (trigger='automatic' — both the scheduled
     * ones from routes/console.php and PreUpdateBackupCommand's
     * pre-update safety net, neither of which an admin asked for by name)
     * are the only ones this ever deletes.
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

        $maxCount = (int) SystemSetting::get('backup.retention_count', $defaultCount);
        $maxAgeDays = (int) SystemSetting::get('backup.retention_max_age_days', $defaultMaxAgeDays);

        $automatic = Backup::query()->where('trigger', '!=', 'manual');

        $expired = (clone $automatic)->where('created_at', '<', now()->subDays($maxAgeDays))->get();
        $excess = (clone $automatic)->orderByDesc('created_at')->get()->slice($maxCount);

        $expired->merge($excess)->unique('id')->each(fn (Backup $b) => $this->delete($b));
    }

    /**
     * Restores a backup (briefing 9.3): reads manifest.json back out of the
     * zip into the same array shape exportLibraries() produces, then hands
     * it to ExportImportService::importLibraries(), which already carries
     * the conflict-resolution logic (rename/merge/overwrite/skip/cancel)
     * shared with ordinary instance-to-instance import (9.1). Both trigger
     * paths from 9.3 use this: BackupController::restore() (admin UI, with
     * $conflictResolutions/$restoreSettings chosen interactively per
     * request) and Console\Commands\RestoreBackupOnBoot
     * (MEDINV_RESTOREBACKUP at container start, unattended).
     */
    public function restore(Backup $backup, User $importingAs, array $conflictResolutions = [], bool $restoreSettings = false): array
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
        // restoreCoverFiles()'s docblock.
        $this->restoreCoverFiles($zip);
        $zip->close();

        return $this->exportImportService->importLibraries($data, $importingAs, $conflictResolutions, $restoreSettings);
    }
}
