<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupService;
use App\Models\Library;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Covers GitHub issue #26: a backup only ever contained manifest.json, so
 * every cover_path it referenced pointed at a file that simply didn't exist
 * once restored — MediaItemController::cover() 404s forever for such an
 * item. BackupService::create()/restore() now carry the referenced cover
 * files inside the same zip, under their own cover_path as the entry name.
 */
class BackupCoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_includes_a_referenced_cover_file_in_the_zip(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('covers/book/dune-AbCdEfGh.jpg', 'fake-cover-bytes');
        $admin = User::factory()->create(['level' => 'admin']);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $admin->id]);
        MediaBook::query()->create([
            'library_id' => $library->id,
            'title' => 'Dune',
            'ean' => '9780000000001',
            'cover_path' => 'covers/book/dune-AbCdEfGh.jpg',
        ]);

        $backup = app(BackupService::class)->create();

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path('backups/'.$backup->filename));
        $this->assertSame('fake-cover-bytes', $zip->getFromName('covers/book/dune-AbCdEfGh.jpg'));
        $zip->close();
    }

    public function test_backup_creation_does_not_fail_when_a_referenced_cover_file_is_already_gone(): void
    {
        Storage::fake('local');
        // No Storage::put() for the cover — simulates a file lost outside the app.
        $admin = User::factory()->create(['level' => 'admin']);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $admin->id]);
        MediaBook::query()->create([
            'library_id' => $library->id,
            'title' => 'Dune',
            'ean' => '9780000000001',
            'cover_path' => 'covers/book/missing-file.jpg',
        ]);

        $backup = app(BackupService::class)->create();

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path('backups/'.$backup->filename));
        $this->assertFalse($zip->getFromName('covers/book/missing-file.jpg'));
        $this->assertIsString($zip->getFromName('manifest.json'));
        $zip->close();
    }

    public function test_restore_writes_the_cover_file_back_onto_the_local_disk(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('covers/book/dune-AbCdEfGh.jpg', 'fake-cover-bytes');
        $admin = User::factory()->create(['level' => 'admin']);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $admin->id]);
        MediaBook::query()->create([
            'library_id' => $library->id,
            'title' => 'Dune',
            'ean' => '9780000000001',
            'cover_path' => 'covers/book/dune-AbCdEfGh.jpg',
        ]);

        $backup = app(BackupService::class)->create();

        // Simulate exactly the loss scenario from the issue: the library and
        // its cover file are both gone, only the backup remains.
        $library->mediaItems()->delete();
        $library->delete();
        Storage::disk('local')->delete('covers/book/dune-AbCdEfGh.jpg');
        $this->assertFalse(Storage::disk('local')->exists('covers/book/dune-AbCdEfGh.jpg'));

        $result = app(BackupService::class)->restore($backup, $admin);

        $this->assertSame(['Novels'], $result['created']);
        $this->assertTrue(Storage::disk('local')->exists('covers/book/dune-AbCdEfGh.jpg'));
        $this->assertSame('fake-cover-bytes', Storage::disk('local')->get('covers/book/dune-AbCdEfGh.jpg'));
        $restored = MediaBook::query()->where('ean', '9780000000001')->first();
        $this->assertSame('covers/book/dune-AbCdEfGh.jpg', $restored->cover_path);
    }

    public function test_restore_ignores_non_cover_entries_in_the_archive(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['level' => 'admin']);
        Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $admin->id]);

        $backup = app(BackupService::class)->create();

        // manifest.json itself must never be (mis)treated as a cover file.
        app(BackupService::class)->restore($backup, $admin, ['__default__' => 'skip']);

        $this->assertFalse(Storage::disk('local')->exists('manifest.json'));
    }
}
