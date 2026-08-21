<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Library;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * GitHub issue #167: uploading a backup .zip an admin already has locally
 * (downloaded earlier from this or another instance) — BackupsPage.tsx
 * previously only ever listed backups already present on this server, with
 * no way to bring one in from outside it. Deliberately the fuller
 * counterpart to ExportImportValidationTest.php's own upload validation
 * coverage for POST /admin/import: this endpoint hands the uploaded file to
 * BackupService::upload() instead, which stores it as an ordinary Backup
 * row reachable through the exact same list/download/restore/delete
 * actions — with the same full restore scope (settings/users/shares) any
 * other backup gets, unlike the plain import endpoint's deliberately
 * narrower library-only restore.
 */
class BackupUploadTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    private function zipWithManifest(string $manifestContent, string $originalName = 'medinv-backup-downloaded.zip'): UploadedFile
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'backup-upload-test');
        $zip = new ZipArchive;
        $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', $manifestContent);
        $zip->close();

        return new UploadedFile($tmpZip, $originalName, 'application/zip', null, true);
    }

    public function test_uploading_a_valid_backup_creates_a_manual_backup_row(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();
        $manifest = json_encode(['libraries' => []]);

        $response = $this->postJson('/api/admin/backups/upload', ['file' => $this->zipWithManifest($manifest)]);

        $response->assertCreated();
        $backup = Backup::query()->latest()->firstOrFail();
        $this->assertSame($backup->filename, $response->json('filename'));
        $this->assertSame('manual', $backup->trigger);
        $this->assertSame('completed', $backup->status);
        $this->assertGreaterThan(0, $backup->size_bytes);
        Storage::disk('local')->assertExists('backups/'.$backup->filename);
        // Never the uploaded file's own original name — see BackupService::upload()'s own comment on why.
        $this->assertNotSame('medinv-backup-downloaded.zip', $backup->filename);
    }

    /** GitHub issue #169: re-uploading a backup keeps documenting the point in time it actually backs up, not the moment it happened to be re-uploaded. */
    public function test_uploading_a_backup_with_the_original_naming_convention_reuses_its_timestamp(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();
        $file = $this->zipWithManifest(json_encode(['libraries' => []]), 'medinv-backup-20250101-120000.zip');

        $response = $this->postJson('/api/admin/backups/upload', ['file' => $file]);

        $response->assertCreated();
        $this->assertSame('medinv-backup-20250101-120000.zip', $response->json('filename'));
    }

    /** A renamed file, or one from outside this app entirely, falls back to the previous behavior — the current time — rather than misreading arbitrary text as a timestamp. */
    public function test_uploading_a_backup_with_a_non_matching_name_falls_back_to_the_current_time(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();
        $file = $this->zipWithManifest(json_encode(['libraries' => []]), 'my-renamed-backup.zip');

        $response = $this->postJson('/api/admin/backups/upload', ['file' => $file]);

        $response->assertCreated();
        $this->assertMatchesRegularExpression('/^medinv-backup-\d{8}-\d{6}\.zip$/', $response->json('filename'));
    }

    /** Re-uploading the exact same file twice (or two originals whose timestamps happen to coincide) must never silently overwrite the first upload. */
    public function test_uploading_the_same_original_timestamp_twice_does_not_collide(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();
        $originalName = 'medinv-backup-20250101-120000.zip';

        $first = $this->postJson('/api/admin/backups/upload', [
            'file' => $this->zipWithManifest(json_encode(['libraries' => []]), $originalName),
        ]);
        $second = $this->postJson('/api/admin/backups/upload', [
            'file' => $this->zipWithManifest(json_encode(['libraries' => []]), $originalName),
        ]);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame('medinv-backup-20250101-120000.zip', $first->json('filename'));
        $this->assertSame('medinv-backup-20250101-120000-1.zip', $second->json('filename'));
        $this->assertSame(2, Backup::query()->count());
        Storage::disk('local')->assertExists('backups/medinv-backup-20250101-120000.zip');
        Storage::disk('local')->assertExists('backups/medinv-backup-20250101-120000-1.zip');
    }

    public function test_an_uploaded_backup_shows_up_in_the_ordinary_list(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $this->postJson('/api/admin/backups/upload', ['file' => $this->zipWithManifest(json_encode(['libraries' => []]))]);
        $response = $this->getJson('/api/admin/backups');

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame('manual', $response->json('0.trigger'));
    }

    /** The actual point of #167: an uploaded backup gets the exact same full restore (settings/users/shares) an on-server-generated one does, via the same restore() endpoint — not the narrower library-only path POST /admin/import offers. */
    public function test_an_uploaded_backup_can_be_restored_with_the_full_scope(): void
    {
        Storage::fake('local');
        $admin = $this->actingAsAdmin();
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $manifest = json_encode([
            'libraries' => [[
                'name' => 'Novels', 'description' => null, 'media_type' => 'book',
                'owner_email' => 'owner@example.com', 'shares' => [],
                'items' => [['title' => 'Dune', 'ean' => '9780000000001']],
            ]],
        ]);

        $uploadResponse = $this->postJson('/api/admin/backups/upload', ['file' => $this->zipWithManifest($manifest)]);
        $backupId = $uploadResponse->json('id');

        $restoreResponse = $this->postJson("/api/admin/backups/{$backupId}/restore", [
            'conflict_resolutions' => ['__default__' => 'overwrite'],
        ]);

        $restoreResponse->assertOk();
        $library = Library::query()->where('name', 'Novels')->firstOrFail();
        $this->assertSame($owner->id, $library->owner_id);
        $this->assertDatabaseHas((new MediaBook)->getTable(), ['title' => 'Dune', 'library_id' => $library->id]);
    }

    public function test_rejects_a_file_that_is_not_a_valid_zip_archive_at_all(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/backups/upload', [
            'file' => UploadedFile::fake()->createWithContent('backup.json', json_encode(['libraries' => []])),
        ]);

        $response->assertStatus(422)->assertJson(['error_code' => 'invalid_backup_file']);
        $this->assertSame(0, Backup::query()->count());
    }

    public function test_rejects_a_zip_missing_manifest_json(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();
        $tmpZip = tempnam(sys_get_temp_dir(), 'backup-upload-test');
        $zip = new ZipArchive;
        $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('not-a-manifest.txt', 'hello');
        $zip->close();

        $response = $this->postJson('/api/admin/backups/upload', [
            'file' => new UploadedFile($tmpZip, 'backup.zip', 'application/zip', null, true),
        ]);

        $response->assertStatus(422)->assertJson(['error_code' => 'invalid_backup_file']);
        $this->assertSame(0, Backup::query()->count());
    }

    public function test_rejects_a_manifest_that_is_not_valid_json(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/backups/upload', ['file' => $this->zipWithManifest('not json at all')]);

        $response->assertStatus(422)->assertJson(['error_code' => 'invalid_backup_file']);
        $this->assertSame(0, Backup::query()->count());
    }

    public function test_requires_a_file(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/backups/upload', []);

        $response->assertStatus(422);
    }

    public function test_requires_admin(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        $response = $this->postJson('/api/admin/backups/upload', [
            'file' => $this->zipWithManifest(json_encode(['libraries' => []])),
        ]);

        $response->assertForbidden();
    }
}
