<?php

namespace Tests\Feature;

use App\Domain\ExportImport\ExportImportService;
use App\Models\Library;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

/**
 * Covers the investigation prompting InvalidImportFileException: before this,
 * uploading a malformed or unrelated file to POST /admin/import either
 * silently "succeeded" with an all-zero result (a missing `libraries` key
 * quietly defaulted to an empty array) or crashed mid-transaction with a raw
 * DB constraint violation once a NOT NULL column (name/media_type/title/ean)
 * turned out missing — neither gave the admin anything actionable. See
 * BackupRestoreLoggingTest for the happy-path import/export coverage this
 * complements.
 *
 * Only the zip format export() produces (manifest.json + covers/, see
 * ExportImportCoverTest) is a valid upload at all — upload() below wraps
 * every payload in one, so what's actually under test here is
 * ExportImportService::assertValidPayload()'s validation of the *decoded*
 * manifest, not the outer file format (that rejection is its own test,
 * below).
 */
class ExportImportValidationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    private function upload(array|string $payload): array
    {
        $content = is_string($payload) ? $payload : json_encode($payload);

        return $this->postJson('/api/admin/import', ['file' => $this->zipWithManifest($content)])->json();
    }

    private function zipWithManifest(string $manifestContent): UploadedFile
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'import-validation-test');
        $zip = new ZipArchive;
        $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', $manifestContent);
        $zip->close();

        return new UploadedFile($tmpZip, 'import.zip', 'application/zip', null, true);
    }

    public function test_rejects_a_file_that_is_not_a_valid_zip_archive_at_all(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/import', [
            'file' => UploadedFile::fake()->createWithContent('import.json', json_encode(['libraries' => []])),
        ]);

        $response->assertStatus(422)->assertJson(['error_code' => 'import_invalid_json']);
    }

    public function test_rejects_a_zip_archive_with_no_manifest_json_inside(): void
    {
        $this->actingAsAdmin();

        $tmpZip = tempnam(sys_get_temp_dir(), 'import-validation-test');
        $zip = new ZipArchive;
        $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('not-a-manifest.json', json_encode(['libraries' => []]));
        $zip->close();

        $response = $this->postJson('/api/admin/import', [
            'file' => new UploadedFile($tmpZip, 'import.zip', 'application/zip', null, true),
        ]);

        $response->assertStatus(422)->assertJson(['error_code' => 'import_invalid_json']);
    }

    public function test_rejects_a_manifest_json_that_is_not_valid_json(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/import', [
            'file' => $this->zipWithManifest('{not valid json'),
        ]);

        $response->assertStatus(422)->assertJson(['error_code' => 'import_invalid_json']);
    }

    public function test_rejects_a_manifest_json_that_is_valid_json_but_not_an_object_or_array_of_libraries(): void
    {
        $this->actingAsAdmin();

        // A syntactically valid JSON scalar — decodes fine, but isn't the
        // array shape importLibraries() expects at all.
        $response = $this->postJson('/api/admin/import', [
            'file' => $this->zipWithManifest('"just a string"'),
        ]);

        $response->assertStatus(422)->assertJson(['error_code' => 'import_invalid_json']);
    }

    public function test_rejects_a_file_with_no_libraries_key_at_all(): void
    {
        $this->actingAsAdmin();

        $result = $this->upload(['format_version' => 1]);

        $this->assertSame('import_missing_libraries', $result['error_code']);
    }

    public function test_rejects_a_library_missing_a_name(): void
    {
        $this->actingAsAdmin();

        $result = $this->upload(['libraries' => [
            ['media_type' => 'book', 'items' => []],
        ]]);

        $this->assertSame('import_invalid_library', $result['error_code']);
        $this->assertSame(['index' => 0, 'field' => 'name'], $result['context']);
    }

    public function test_rejects_a_library_with_an_invalid_media_type(): void
    {
        $this->actingAsAdmin();

        $result = $this->upload(['libraries' => [
            ['name' => 'Novels', 'media_type' => 'vinyl', 'items' => []],
        ]]);

        $this->assertSame('import_invalid_library', $result['error_code']);
        $this->assertSame(['index' => 0, 'field' => 'media_type'], $result['context']);
    }

    public function test_rejects_an_item_missing_an_ean(): void
    {
        $this->actingAsAdmin();

        $result = $this->upload(['libraries' => [
            ['name' => 'Novels', 'media_type' => 'book', 'items' => [
                ['title' => 'Dune'],
            ]],
        ]]);

        $this->assertSame('import_invalid_item', $result['error_code']);
        $this->assertSame(['library' => 'Novels', 'index' => 0], $result['context']);
    }

    public function test_a_genuine_export_still_imports_successfully(): void
    {
        $admin = $this->actingAsAdmin();
        Library::query()->create(['name' => 'Real Library', 'media_type' => 'book', 'owner_id' => $admin->id]);
        $export = app(ExportImportService::class)->exportLibraries(null);
        $file = $this->zipWithManifest(json_encode($export));

        // Re-importing an export of the instance's own current state hits the
        // default "skip" conflict resolution (the library already exists) —
        // the point here is only that assertValidPayload() doesn't reject a
        // genuine, well-formed export, not the conflict-resolution behavior
        // itself (already covered by BackupRestoreTest).
        $this->postJson('/api/admin/import', ['file' => $file])
            ->assertOk()
            ->assertJson(['skipped' => ['Real Library']]);
    }
}
