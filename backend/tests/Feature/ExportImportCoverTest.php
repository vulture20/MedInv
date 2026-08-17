<?php

namespace Tests\Feature;

use App\Domain\ExportImport\ExportImportService;
use App\Models\Library;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * An ordinary library export previously shipped bare manifest JSON, unlike a
 * full backup (GitHub issue #26) — every referenced cover_path pointed at a
 * file that simply doesn't exist on the receiving instance, since it only
 * resolves relative to the exporting instance's own `local` disk (see
 * CoverDownloadService). export()/import() now carry covers inside a zip,
 * the same mechanism BackupService already used — a bare JSON file (the
 * only format this endpoint used to accept) is deliberately no longer
 * valid, since it can never carry covers at all (see
 * ExportImportValidationTest for the corresponding rejection coverage).
 */
class ExportImportCoverTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_export_response_is_a_zip_containing_manifest_and_the_referenced_cover(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('covers/book/dune-AbCdEfGh.jpg', 'fake-cover-bytes');
        $admin = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $admin->id]);
        MediaBook::query()->create([
            'library_id' => $library->id,
            'title' => 'Dune',
            'ean' => '9780000000001',
            'cover_path' => 'covers/book/dune-AbCdEfGh.jpg',
        ]);

        $response = $this->postJson('/api/admin/export');
        $response->assertOk();

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('.zip', $disposition);

        $tmpFile = tempnam(sys_get_temp_dir(), 'export-test');
        file_put_contents($tmpFile, $response->streamedContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmpFile) === true);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertSame('Novels', $manifest['libraries'][0]['name']);
        $this->assertSame('fake-cover-bytes', $zip->getFromName('covers/book/dune-AbCdEfGh.jpg'));
        $zip->close();
        unlink($tmpFile);
    }

    public function test_export_does_not_fail_when_a_referenced_cover_file_is_already_gone(): void
    {
        Storage::fake('local');
        $admin = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $admin->id]);
        MediaBook::query()->create([
            'library_id' => $library->id,
            'title' => 'Dune',
            'ean' => '9780000000001',
            'cover_path' => 'covers/book/missing-file.jpg',
        ]);

        $this->postJson('/api/admin/export')->assertOk();
    }

    /**
     * GitHub-reported bug: addCoverFilesToZip() only ever added the
     * full-size cover_path itself — the thumbnail CoverDownloadService
     * generates alongside every cover (served by
     * MediaItemController::coverThumbnail() for library list views) was
     * never included, so a restored/imported instance's list views quietly
     * fell back to shipping the full-size image to every row.
     */
    public function test_export_zip_also_contains_the_covers_thumbnail(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('covers/book/dune-AbCdEfGh.jpg', 'fake-cover-bytes');
        Storage::disk('local')->put('covers/book/thumb_dune-AbCdEfGh.jpg', 'fake-thumbnail-bytes');
        $admin = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $admin->id]);
        MediaBook::query()->create([
            'library_id' => $library->id,
            'title' => 'Dune',
            'ean' => '9780000000001',
            'cover_path' => 'covers/book/dune-AbCdEfGh.jpg',
        ]);

        $response = $this->postJson('/api/admin/export');
        $tmpFile = tempnam(sys_get_temp_dir(), 'export-test');
        file_put_contents($tmpFile, $response->streamedContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmpFile) === true);
        $this->assertSame('fake-cover-bytes', $zip->getFromName('covers/book/dune-AbCdEfGh.jpg'));
        $this->assertSame('fake-thumbnail-bytes', $zip->getFromName('covers/book/thumb_dune-AbCdEfGh.jpg'));
        $zip->close();
        unlink($tmpFile);
    }

    /** Mirrors the cover's own best-effort handling above — a thumbnail that never generated (CoverDownloadService::generateThumbnail() is itself best-effort) must not fail the export either. */
    public function test_export_does_not_fail_when_a_covers_thumbnail_was_never_generated(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('covers/book/dune-AbCdEfGh.jpg', 'fake-cover-bytes');
        // No thumb_dune-AbCdEfGh.jpg on disk at all.
        $admin = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $admin->id]);
        MediaBook::query()->create([
            'library_id' => $library->id,
            'title' => 'Dune',
            'ean' => '9780000000001',
            'cover_path' => 'covers/book/dune-AbCdEfGh.jpg',
        ]);

        $this->postJson('/api/admin/export')->assertOk();
    }

    public function test_import_from_a_zip_restores_the_cover_file_onto_the_local_disk(): void
    {
        Storage::fake('local');
        $admin = $this->actingAsAdmin();
        $export = app(ExportImportService::class)->exportLibraries(null);
        $export['libraries'][] = [
            'name' => 'Novels',
            'description' => null,
            'media_type' => 'book',
            'shares' => [],
            'items' => [[
                'title' => 'Dune',
                'ean' => '9780000000001',
                'cover_path' => 'covers/book/dune-AbCdEfGh.jpg',
            ]],
        ];

        $tmpZip = tempnam(sys_get_temp_dir(), 'import-test');
        $zip = new ZipArchive;
        $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($export));
        $zip->addFromString('covers/book/dune-AbCdEfGh.jpg', 'fake-cover-bytes');
        $zip->close();

        $file = new UploadedFile($tmpZip, 'export.zip', 'application/zip', null, true);

        $response = $this->postJson('/api/admin/import', ['file' => $file]);

        $response->assertOk()->assertJson(['created' => ['Novels']]);
        $this->assertTrue(Storage::disk('local')->exists('covers/book/dune-AbCdEfGh.jpg'));
        $this->assertSame('fake-cover-bytes', Storage::disk('local')->get('covers/book/dune-AbCdEfGh.jpg'));
        $imported = MediaBook::query()->where('ean', '9780000000001')->first();
        $this->assertSame('covers/book/dune-AbCdEfGh.jpg', $imported->cover_path);
    }

    public function test_import_rejects_a_bare_json_export_even_when_well_formed(): void
    {
        $admin = $this->actingAsAdmin();
        Library::query()->create(['name' => 'Existing', 'media_type' => 'book', 'owner_id' => $admin->id]);
        $export = app(ExportImportService::class)->exportLibraries(null);
        $file = UploadedFile::fake()->createWithContent('export.json', json_encode($export));

        $this->postJson('/api/admin/import', ['file' => $file])
            ->assertStatus(422)
            ->assertJson(['error_code' => 'import_invalid_json']);
    }

    public function test_a_round_trip_export_then_import_preserves_the_cover(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('covers/book/dune-AbCdEfGh.jpg', 'fake-cover-bytes');
        Storage::disk('local')->put('covers/book/thumb_dune-AbCdEfGh.jpg', 'fake-thumbnail-bytes');
        $admin = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $admin->id]);
        MediaBook::query()->create([
            'library_id' => $library->id,
            'title' => 'Dune',
            'ean' => '9780000000001',
            'cover_path' => 'covers/book/dune-AbCdEfGh.jpg',
        ]);

        $response = $this->postJson('/api/admin/export', ['library_ids' => [$library->id]]);
        $tmpFile = tempnam(sys_get_temp_dir(), 'roundtrip-test');
        file_put_contents($tmpFile, $response->streamedContent());

        // Simulate a fresh receiving instance: the library and its cover+thumbnail
        // are both gone, only the exported zip remains.
        $library->mediaItems()->delete();
        $library->delete();
        Storage::disk('local')->delete(['covers/book/dune-AbCdEfGh.jpg', 'covers/book/thumb_dune-AbCdEfGh.jpg']);

        $file = new UploadedFile($tmpFile, 'export.zip', 'application/zip', null, true);
        $result = $this->postJson('/api/admin/import', ['file' => $file])->json();

        $this->assertSame(['Novels'], $result['created']);
        $this->assertTrue(Storage::disk('local')->exists('covers/book/dune-AbCdEfGh.jpg'));
        $this->assertSame('fake-cover-bytes', Storage::disk('local')->get('covers/book/dune-AbCdEfGh.jpg'));
        $this->assertTrue(Storage::disk('local')->exists('covers/book/thumb_dune-AbCdEfGh.jpg'));
        $this->assertSame('fake-thumbnail-bytes', Storage::disk('local')->get('covers/book/thumb_dune-AbCdEfGh.jpg'));
    }
}
