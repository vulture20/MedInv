<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupService;
use App\Models\Backup;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GitHub-reported gap: the admin UI's backup list and prune()'s retention
 * both work purely off the `backups` table, never the filesystem — a .zip
 * that ends up in storage/app/private/backups without a matching row
 * (reported concretely: 8 shown in the UI vs. 50 files actually present,
 * most plausibly GitHub issue #25's now-fixed bug where every container
 * recreation used to reset the database while the separately-mounted
 * `backups` volume kept every file ever written) is simultaneously
 * invisible in the UI and permanently exempt from cleanup.
 * BackupService::reconcileWithDisk() recreates a row for any such orphan;
 * see its own docblock for the full reasoning.
 */
class BackupReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_reconciles_a_zip_file_on_disk_with_no_matching_row(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('backups/medinv-backup-20260101-000000.zip', 'zip bytes');

        $reconciled = app(BackupService::class)->reconcileWithDisk();

        $this->assertSame(1, $reconciled);
        $backup = Backup::query()->where('filename', 'medinv-backup-20260101-000000.zip')->first();
        $this->assertNotNull($backup);
        $this->assertSame('manual', $backup->trigger);
        $this->assertSame('completed', $backup->status);
    }

    /** trigger: 'manual' is what actually matters here — prune() exempts manual backups entirely, so a reconciled file is never silently swept away by a retention policy that never chose to keep it. */
    public function test_reconciled_backups_are_exempt_from_automatic_pruning(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('backups/medinv-backup-20200101-000000.zip', 'very old zip bytes');
        SystemSetting::set('backup.retention_mode', 'age');
        SystemSetting::set('backup.retention_max_age_days', 1);

        app(BackupService::class)->reconcileWithDisk();
        app(BackupService::class)->prune();

        Storage::disk('local')->assertExists('backups/medinv-backup-20200101-000000.zip');
        $this->assertNotNull(Backup::query()->where('filename', 'medinv-backup-20200101-000000.zip')->first());
    }

    public function test_backdates_the_reconciled_row_to_the_files_actual_mtime(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('backups/medinv-backup-20200601-120000.zip', 'bytes');
        $oldTimestamp = now()->subYears(2)->timestamp;
        touch(Storage::disk('local')->path('backups/medinv-backup-20200601-120000.zip'), $oldTimestamp);

        app(BackupService::class)->reconcileWithDisk();

        $backup = Backup::query()->where('filename', 'medinv-backup-20200601-120000.zip')->first();
        $this->assertSame($oldTimestamp, $backup->created_at->timestamp);
    }

    public function test_does_not_duplicate_a_row_for_an_already_tracked_file(): void
    {
        Storage::fake('local');
        $backup = app(BackupService::class)->create();
        $this->assertSame(1, Backup::query()->count());

        $reconciled = app(BackupService::class)->reconcileWithDisk();

        $this->assertSame(0, $reconciled);
        $this->assertSame(1, Backup::query()->where('filename', $backup->filename)->count());
    }

    public function test_ignores_non_zip_files_in_the_backups_directory(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('backups/.gitkeep', '');
        Storage::disk('local')->put('backups/readme.txt', 'not a backup');

        $reconciled = app(BackupService::class)->reconcileWithDisk();

        $this->assertSame(0, $reconciled);
        $this->assertSame(0, Backup::query()->count());
    }

    public function test_handles_a_missing_backups_directory_gracefully(): void
    {
        Storage::fake('local');

        $reconciled = app(BackupService::class)->reconcileWithDisk();

        $this->assertSame(0, $reconciled);
    }

    public function test_listing_backups_via_the_api_self_heals_the_missing_row(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('backups/medinv-backup-20260101-000000.zip', 'zip bytes');
        $this->actingAsAdmin();

        $response = $this->getJson('/api/admin/backups');

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame('medinv-backup-20260101-000000.zip', $response->json('0.filename'));
    }
}
