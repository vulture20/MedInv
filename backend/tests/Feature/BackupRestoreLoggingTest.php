<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupService;
use App\Domain\ExportImport\ExportImportService;
use App\Models\Backup;
use App\Models\Library;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Backup/restore/prune/export/import audit trail — all of these previously
 * went completely unlogged on success. See AuthLoggingTest's docblock for
 * why every test here also loosely allows Log::debug() (LogFrontendAccess's
 * per-request entry).
 */
class BackupRestoreLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_creating_a_backup_is_logged(): void
    {
        $this->actingAsAdmin();

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Backup created', Mockery::on(function ($context) {
            return $context['trigger'] === 'manual'
                && $context['reason'] === null
                && str_starts_with($context['filename'], 'medinv-backup-');
        }));

        $this->postJson('/api/admin/backups')->assertCreated();
    }

    public function test_pruning_backups_is_logged_with_count_and_filenames(): void
    {
        SystemSetting::set('backup.retention_count', 1);
        $old = Backup::query()->create(['filename' => 'old.zip', 'size_bytes' => 1, 'trigger' => 'automatic', 'status' => 'completed']);
        $old->forceFill(['created_at' => now()->subDays(5)])->save();

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Backup created', Mockery::any());
        Log::shouldReceive('info')->once()->with('Backups pruned', Mockery::on(function ($context) {
            return $context['count'] === 1 && $context['filenames'] === ['old.zip'];
        }));

        app(BackupService::class)->create(trigger: 'automatic', intervalMode: 'daily');
    }

    public function test_pruning_is_not_logged_when_nothing_needs_pruning(): void
    {
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Backup created', Mockery::any());
        Log::shouldReceive('info')->never()->with('Backups pruned', Mockery::any());

        app(BackupService::class)->create(trigger: 'automatic', intervalMode: 'daily');
    }

    /**
     * BackupController::destroy() logs this itself, deliberately not inside
     * BackupService::delete() — that method is shared with prune()'s
     * automatic retention cleanup, which already logs its own bulk
     * "Backups pruned" entry (see test_pruning_backups_is_logged_with_count_
     * and_filenames above); logging again inside delete() itself would
     * double-log every automatically pruned backup. A manually deleted
     * backup previously had no log entry at all.
     */
    public function test_deleting_a_backup_is_logged_with_the_filename(): void
    {
        $admin = $this->actingAsAdmin();
        $backup = app(BackupService::class)->create(trigger: 'manual');

        // Log isn't mocked yet during the create() call above (same ordering as
        // test_restoring_a_backup_is_logged_with_result_counts) — only the
        // delete() call below is actually under observation here.
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Backup deleted', Mockery::on(function ($context) use ($admin, $backup) {
            return $context['actor_id'] === $admin->id && $context['filename'] === $backup->filename;
        }));

        $this->deleteJson("/api/admin/backups/{$backup->id}")->assertNoContent();
    }

    public function test_restoring_a_backup_is_logged_with_result_counts(): void
    {
        $admin = $this->actingAsAdmin();
        $backup = app(BackupService::class)->create(trigger: 'manual');

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Backup restored', Mockery::on(function ($context) use ($admin, $backup) {
            return $context['filename'] === $backup->filename
                && $context['actor_id'] === $admin->id
                && $context['restore_settings'] === false
                && $context['created'] === 0;
        }));

        $this->postJson("/api/admin/backups/{$backup->id}/restore")->assertOk();
    }

    public function test_exporting_libraries_is_logged_with_the_library_count(): void
    {
        $admin = $this->actingAsAdmin();
        Library::query()->create(['name' => 'Exported', 'media_type' => 'book', 'owner_id' => $admin->id]);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Libraries exported', Mockery::on(function ($context) use ($admin) {
            return $context['actor_id'] === $admin->id && $context['library_ids'] === 'all' && $context['library_count'] === 1;
        }));

        $this->postJson('/api/admin/export')->assertOk();
    }

    public function test_importing_libraries_is_logged_with_result_counts(): void
    {
        $admin = $this->actingAsAdmin();
        $export = app(ExportImportService::class)->exportLibraries(null);
        $file = UploadedFile::fake()->createWithContent('export.json', json_encode($export));

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Libraries imported', Mockery::on(function ($context) use ($admin) {
            return $context['actor_id'] === $admin->id && $context['restore_settings'] === false;
        }));

        $this->postJson('/api/admin/import', ['file' => $file])->assertOk();
    }

    public function test_restore_on_boot_logs_a_warning_when_the_named_backup_does_not_exist(): void
    {
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->once()->with(Mockery::on(function ($message) {
            return str_contains($message, 'no backup with that filename exists');
        }));

        $this->artisan('medinv:restore-backup', ['name' => 'does-not-exist'])->assertExitCode(1);
    }
}
