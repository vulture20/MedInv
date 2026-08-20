<?php

namespace Tests\Feature;

use App\Domain\ExportImport\ExportImportService;
use App\Models\Library;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #152: exportLibraries() previously included no information
 * at all about a library's owner or its `is_sample_library` flag —
 * createLibraryFromExport() unconditionally assigned the *importing* user
 * as owner instead, real data loss for a full backup/restore
 * (BackupService/MEDINV_RESTOREBACKUP), whose whole point is reproducing
 * the exact prior state. `owner_email` now travels the same way
 * `shares[].user_email` already does — resolved by email, falling back to
 * the importing user (not a hard failure) when there's no match, the same
 * tolerance restoreShares() already shows for a stale share.
 */
class LibraryOwnerRestoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_includes_the_librarys_owner_email(): void
    {
        $owner = User::factory()->create(['email' => 'original-owner@example.com']);
        Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $export = app(ExportImportService::class)->exportLibraries(null);

        $this->assertSame('original-owner@example.com', $export['libraries'][0]['owner_email']);
    }

    public function test_import_restores_ownership_to_the_matching_account_instead_of_the_importer(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);
        $originalOwner = User::factory()->create(['email' => 'original-owner@example.com']);

        $data = [
            'libraries' => [[
                'name' => 'Novels', 'description' => null, 'media_type' => 'book',
                'owner_email' => 'original-owner@example.com', 'shares' => [], 'items' => [],
            ]],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin);

        $library = Library::query()->where('name', 'Novels')->firstOrFail();
        $this->assertSame($originalOwner->id, $library->owner_id);
        $this->assertNotSame($admin->id, $library->owner_id);
    }

    public function test_import_falls_back_to_the_importing_user_when_the_owner_email_matches_no_account(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);

        $data = [
            'libraries' => [[
                'name' => 'Novels', 'description' => null, 'media_type' => 'book',
                'owner_email' => 'long-gone@example.com', 'shares' => [], 'items' => [],
            ]],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin);

        $library = Library::query()->where('name', 'Novels')->firstOrFail();
        $this->assertSame($admin->id, $library->owner_id);
    }

    /** An export from before GitHub issue #152 has no `owner_email` key at all — must not fail, same fallback as an unresolvable email. */
    public function test_import_falls_back_to_the_importing_user_when_owner_email_is_absent_entirely(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);

        $data = [
            'libraries' => [[
                'name' => 'Novels', 'description' => null, 'media_type' => 'book', 'shares' => [], 'items' => [],
            ]],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin);

        $library = Library::query()->where('name', 'Novels')->firstOrFail();
        $this->assertSame($admin->id, $library->owner_id);
    }

    public function test_is_sample_library_round_trips_through_export_and_import(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        Library::query()->create(['name' => 'Sample Books', 'media_type' => 'book', 'owner_id' => $owner->id, 'is_sample_library' => true]);

        $export = app(ExportImportService::class)->exportLibraries(null);
        $this->assertTrue($export['libraries'][0]['is_sample_library']);

        // Simulate importing that same export onto a fresh instance.
        $export['libraries'][0]['name'] = 'Sample Books (restored)';
        app(ExportImportService::class)->importLibraries($export, $admin);

        $restored = Library::query()->where('name', 'Sample Books (restored)')->firstOrFail();
        $this->assertTrue($restored->is_sample_library);
    }

    /** Absent from an older export (predating GitHub issue #152) — defaults to false, matching the column's own DB default rather than failing. */
    public function test_is_sample_library_defaults_to_false_when_absent_from_the_import_data(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);

        $data = ['libraries' => [['name' => 'Novels', 'description' => null, 'media_type' => 'book', 'shares' => [], 'items' => []]]];

        app(ExportImportService::class)->importLibraries($data, $admin);

        $library = Library::query()->where('name', 'Novels')->firstOrFail();
        $this->assertFalse($library->is_sample_library);
    }
}
