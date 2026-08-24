<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupService;
use App\Domain\ExportImport\ExportImportService;
use App\Models\Library;
use App\Models\LibraryUserPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * GitHub issue #180 (a direct follow-up question after GitHub issue #179
 * shipped: "Landen diese Einstellungen auch schon im Backup?"): each
 * library's LibraryUserPreference rows are exported/restored the same
 * "resolve by email, gated behind $includeUsers, present-key-gated on
 * restore" way `captured_by_email` already is (see
 * CapturedByBackupTest) — this is personal, per-user data (who excluded a
 * library from *their own* Statistics/Reports/Dashboard), not library
 * structure like `shares` that an ordinary instance-to-instance export
 * needs unconditionally.
 */
class LibraryUserPreferenceBackupTest extends TestCase
{
    use RefreshDatabase;

    private function library(int $ownerId): Library
    {
        return Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $ownerId]);
    }

    public function test_a_real_backup_includes_user_preferences(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $reader = User::factory()->create(['email' => 'reader@example.com']);
        $library = $this->library($owner->id);
        LibraryUserPreference::query()->create([
            'library_id' => $library->id, 'user_id' => $reader->id,
            'exclude_from_statistics' => true, 'exclude_from_reports' => false, 'exclude_from_dashboard' => true,
        ]);

        $export = app(ExportImportService::class)->exportLibraries(null, includeUsers: true);

        $preference = $export['libraries'][0]['user_preferences'][0];
        $this->assertSame('reader@example.com', $preference['user_email']);
        $this->assertTrue($preference['exclude_from_statistics']);
        $this->assertFalse($preference['exclude_from_reports']);
        $this->assertTrue($preference['exclude_from_dashboard']);
    }

    public function test_an_ordinary_export_omits_user_preferences_entirely(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $reader = User::factory()->create(['email' => 'reader@example.com']);
        $library = $this->library($owner->id);
        LibraryUserPreference::query()->create(['library_id' => $library->id, 'user_id' => $reader->id, 'exclude_from_statistics' => true]);

        $export = app(ExportImportService::class)->exportLibraries(null);

        $this->assertArrayNotHasKey('user_preferences', $export['libraries'][0]);
    }

    public function test_restoring_resolves_user_preferences_to_the_matching_account(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);
        $reader = User::factory()->create(['email' => 'reader@example.com']);

        $data = [
            'libraries' => [[
                'name' => 'Novels', 'description' => null, 'media_type' => 'book', 'shares' => [],
                'user_preferences' => [[
                    'user_email' => 'reader@example.com',
                    'exclude_from_statistics' => true, 'exclude_from_reports' => true, 'exclude_from_dashboard' => false,
                ]],
                'items' => [],
            ]],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin);

        $library = Library::query()->where('name', 'Novels')->firstOrFail();
        $preference = LibraryUserPreference::query()->where('library_id', $library->id)->where('user_id', $reader->id)->firstOrFail();
        $this->assertTrue($preference->exclude_from_statistics);
        $this->assertTrue($preference->exclude_from_reports);
        $this->assertFalse($preference->exclude_from_dashboard);
    }

    public function test_restoring_with_no_matching_account_skips_that_entry(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);

        $data = [
            'libraries' => [[
                'name' => 'Novels', 'description' => null, 'media_type' => 'book', 'shares' => [],
                'user_preferences' => [['user_email' => 'long-gone@example.com', 'exclude_from_statistics' => true]],
                'items' => [],
            ]],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin);

        $library = Library::query()->where('name', 'Novels')->firstOrFail();
        $this->assertSame(0, LibraryUserPreference::query()->where('library_id', $library->id)->count());
    }

    /**
     * The core safety property: a payload with no `user_preferences` key at
     * all (an ordinary library export/import, or a pre-#180 backup) must
     * never wipe out preferences that already exist locally for an
     * overwritten library — those weren't part of what this import claims
     * to represent at all.
     */
    public function test_overwriting_a_library_with_no_user_preferences_key_leaves_existing_ones_untouched(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);
        $reader = User::factory()->create(['email' => 'reader@example.com']);
        $library = $this->library($admin->id);
        LibraryUserPreference::query()->create(['library_id' => $library->id, 'user_id' => $reader->id, 'exclude_from_dashboard' => true]);

        $data = [
            'libraries' => [[
                'name' => 'Novels', 'description' => 'Updated via plain import', 'media_type' => 'book', 'shares' => [], 'items' => [],
            ]],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin, conflictResolutions: ['__default__' => 'overwrite']);

        $preference = LibraryUserPreference::query()->where('library_id', $library->id)->where('user_id', $reader->id)->first();
        $this->assertNotNull($preference);
        $this->assertTrue($preference->exclude_from_dashboard);
    }

    /** Overwriting a library *with* a `user_preferences` key fully replaces the existing set, same as shares. */
    public function test_overwriting_a_library_with_a_user_preferences_key_replaces_the_existing_set(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);
        $staleReader = User::factory()->create(['email' => 'stale@example.com']);
        $newReader = User::factory()->create(['email' => 'new@example.com']);
        $library = $this->library($admin->id);
        LibraryUserPreference::query()->create(['library_id' => $library->id, 'user_id' => $staleReader->id, 'exclude_from_statistics' => true]);

        $data = [
            'libraries' => [[
                'name' => 'Novels', 'description' => null, 'media_type' => 'book', 'shares' => [],
                'user_preferences' => [['user_email' => 'new@example.com', 'exclude_from_dashboard' => true]],
                'items' => [],
            ]],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin, conflictResolutions: ['__default__' => 'overwrite']);

        $this->assertSame(0, LibraryUserPreference::query()->where('library_id', $library->id)->where('user_id', $staleReader->id)->count());
        $this->assertTrue(LibraryUserPreference::query()->where('library_id', $library->id)->where('user_id', $newReader->id)->first()->exclude_from_dashboard);
    }

    /** Full round trip through the real zip-based backup/restore path (BackupService), not just importLibraries() called directly. */
    public function test_a_full_backup_restore_preserves_user_preferences(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['level' => 'admin']);
        $reader = User::factory()->create(['email' => 'reader@example.com']);
        $library = $this->library($admin->id);
        LibraryUserPreference::query()->create(['library_id' => $library->id, 'user_id' => $reader->id, 'exclude_from_reports' => true]);

        $backup = app(BackupService::class)->create();
        $manifest = $this->readManifest($backup->filename);
        $this->assertSame('reader@example.com', $manifest['libraries'][0]['user_preferences'][0]['user_email']);

        LibraryUserPreference::query()->where('library_id', $library->id)->delete();

        app(BackupService::class)->restore($backup, $admin, conflictResolutions: ['__default__' => 'overwrite'], restoreSettings: false, restoreShares: false);

        $restoredLibrary = Library::query()->where('name', 'Novels')->firstOrFail();
        $preference = LibraryUserPreference::query()->where('library_id', $restoredLibrary->id)->where('user_id', $reader->id)->firstOrFail();
        $this->assertTrue($preference->exclude_from_reports);
    }

    private function readManifest(string $filename): array
    {
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path('backups/'.$filename));
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        return $manifest;
    }
}
