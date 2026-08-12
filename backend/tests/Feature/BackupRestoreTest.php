<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupService;
use App\Models\Library;
use App\Models\MediaBook;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers GitHub issue #2: BackupService::restore() used to throw "not yet
 * implemented" — this exercises both the admin-UI trigger path (arbitrary
 * per-library conflict resolutions) and RestoreBackupOnBoot's unattended
 * `__default__` path (MEDINV_RESTOREBACKUP, briefing 9.3).
 */
class BackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_recreates_a_library_that_no_longer_exists(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['level' => 'admin']);

        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $admin->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);

        $backup = app(BackupService::class)->create();
        $library->mediaItems()->delete();
        $library->delete();

        $result = app(BackupService::class)->restore($backup, $admin);

        $this->assertSame(['Novels'], $result['created']);
        $this->assertDatabaseHas((new Library)->getTable(), ['name' => 'Novels']);
        $restored = Library::query()->where('name', 'Novels')->first();
        $this->assertSame(1, $restored->mediaItems()->count());
    }

    public function test_restore_skips_a_conflicting_library_by_default(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['level' => 'admin']);
        Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $admin->id]);

        $backup = app(BackupService::class)->create();
        $result = app(BackupService::class)->restore($backup, $admin);

        $this->assertSame(['Novels'], $result['skipped']);
        $this->assertSame(1, Library::query()->where('name', 'Novels')->count());
    }

    public function test_restore_overwrites_a_conflicting_library_when_told_to(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['level' => 'admin']);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $admin->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);

        $backup = app(BackupService::class)->create();
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Newer Book', 'ean' => '9780000000002']);

        $result = app(BackupService::class)->restore($backup, $admin, ['Novels' => 'overwrite']);

        $this->assertSame(['Novels'], $result['overwritten']);
        $titles = $library->mediaItems()->pluck('title')->all();
        $this->assertSame(['Dune'], $titles);
    }

    public function test_restore_applies_default_resolution_to_every_unlisted_library(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['level' => 'admin']);
        Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $admin->id]);
        Library::query()->create(['name' => 'Comics', 'media_type' => 'book', 'owner_id' => $admin->id]);

        $backup = app(BackupService::class)->create();

        $result = app(BackupService::class)->restore($backup, $admin, ['__default__' => 'overwrite']);

        $this->assertEqualsCanonicalizing(['Novels', 'Comics'], $result['overwritten']);
    }

    public function test_restore_settings_true_restores_system_settings_and_users(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['level' => 'admin']);
        SystemSetting::set('security.throttle_max_attempts', 3);

        $backup = app(BackupService::class)->create();
        SystemSetting::set('security.throttle_max_attempts', 6);

        app(BackupService::class)->restore($backup, $admin, [], restoreSettings: true);

        $this->assertSame(3, SystemSetting::get('security.throttle_max_attempts'));
    }
}
