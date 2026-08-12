<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupService;
use App\Models\Library;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Covers the `medinv:restore-backup` console command (MEDINV_RESTOREBACKUP, briefing 9.3). */
class RestoreBackupOnBootTest extends TestCase
{
    use RefreshDatabase;

    public function test_restores_using_the_predefined_admin_and_overwrites_conflicts(): void
    {
        Storage::fake('local');
        $protectedAdmin = User::factory()->create(['level' => 'admin', 'is_protected' => true]);
        Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $protectedAdmin->id]);

        $backup = app(BackupService::class)->create();
        // A second conflicting library not present in the backup at all — should be untouched.
        Library::query()->create(['name' => 'Comics', 'media_type' => 'book', 'owner_id' => $protectedAdmin->id]);

        $this->artisan('medinv:restore-backup', ['name' => $backup->filename])
            ->assertExitCode(0);

        $this->assertDatabaseHas((new Library)->getTable(), ['name' => 'Novels']);
        $this->assertDatabaseHas((new Library)->getTable(), ['name' => 'Comics']);
    }

    public function test_accepts_a_filename_without_the_zip_extension(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['level' => 'admin', 'is_protected' => true]);
        $backup = app(BackupService::class)->create();
        $nameWithoutExtension = preg_replace('/\.zip$/', '', $backup->filename);

        $this->artisan('medinv:restore-backup', ['name' => $nameWithoutExtension])
            ->assertExitCode(0);
    }

    public function test_fails_cleanly_for_an_unknown_backup_filename(): void
    {
        User::factory()->create(['level' => 'admin', 'is_protected' => true]);

        $this->artisan('medinv:restore-backup', ['name' => 'does-not-exist'])
            ->assertExitCode(1);
    }
}
