<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupService;
use App\Models\Library;
use App\Models\LibraryShare;
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

    /**
     * GitHub issue #80: MEDINV_RESTOREBACKUP's whole purpose is bringing
     * the instance to exactly the backed-up state (same reasoning already
     * documented for restoreSettings) — shares must come back too, without
     * needing their own env var/opt-in the way the interactive admin-UI
     * restore does.
     */
    public function test_restores_shares_without_being_asked(): void
    {
        Storage::fake('local');
        $protectedAdmin = User::factory()->create(['level' => 'admin', 'is_protected' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $protectedAdmin->id]);
        LibraryShare::query()->create(['library_id' => $library->id, 'scope' => 'all_users']);

        $backup = app(BackupService::class)->create();
        $library->mediaItems()->delete();
        $library->delete();

        $this->artisan('medinv:restore-backup', ['name' => $backup->filename])
            ->assertExitCode(0);

        $restored = Library::query()->where('name', 'Novels')->firstOrFail();
        $this->assertDatabaseHas((new LibraryShare)->getTable(), ['library_id' => $restored->id, 'scope' => 'all_users']);
    }

    public function test_fails_cleanly_for_an_unknown_backup_filename(): void
    {
        User::factory()->create(['level' => 'admin', 'is_protected' => true]);

        $this->artisan('medinv:restore-backup', ['name' => 'does-not-exist'])
            ->assertExitCode(1);
    }
}
