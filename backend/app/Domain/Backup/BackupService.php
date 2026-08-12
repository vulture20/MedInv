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
 * is a zip containing manifest.json, the same structure ExportImportService
 * produces for a full ("alle") export — restoration therefore reuses
 * ExportImportService::importLibraries() and its conflict-resolution modes.
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

    public function create(string $trigger = 'manual', ?string $intervalMode = null): Backup
    {
        // includeUsers: true — a backup is a full snapshot of this instance (briefing
        // 9.2), unlike an ordinary admin-initiated library export (9.1), which never
        // carries user accounts/password hashes. See exportLibraries()'s docblock.
        $data = $this->exportImportService->exportLibraries(null, includeUsers: true);
        $filename = 'medinv-backup-'.now()->format('Ymd-His').'.zip';
        $path = self::DIR.'/'.$filename;

        $tmpJson = tempnam(sys_get_temp_dir(), 'medinv-backup');
        file_put_contents($tmpJson, json_encode($data, JSON_PRETTY_PRINT));

        $zip = new ZipArchive;
        $absolutePath = Storage::disk(self::DISK)->path($path);
        Storage::disk(self::DISK)->makeDirectory(self::DIR);
        $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($tmpJson, 'manifest.json');
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
     * the configured max age are deleted automatically.
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

        $expired = Backup::query()->where('created_at', '<', now()->subDays($maxAgeDays))->get();
        $excess = Backup::query()->orderByDesc('created_at')->get()->slice($maxCount);

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
        $zip->close();

        if ($manifest === false) {
            throw new \RuntimeException("Backup archive is missing manifest.json: {$backup->filename}");
        }

        $data = json_decode($manifest, true);

        if (! is_array($data)) {
            throw new \RuntimeException("Backup archive contains an invalid manifest.json: {$backup->filename}");
        }

        return $this->exportImportService->importLibraries($data, $importingAs, $conflictResolutions, $restoreSettings);
    }
}
