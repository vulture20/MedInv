<?php

namespace Tests\Feature;

use App\Domain\Metadata\CoverCleanupService;
use App\Domain\Metadata\CoverDownloadService;
use App\Models\Library;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\MediaDvdBluray;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Daily orphaned-cover-file sweep. Every cover-owning write path already
 * cleans up its own files (upload/delete/item-delete — see
 * MediaItemController), but a crash mid-request, a partially-reconciled
 * backup restore, or direct database manipulation can still leave a file
 * on disk that nothing references anymore — CoverCleanupService is the
 * garbage-collection pass that catches those.
 */
class CoverCleanupServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function library(string $mediaType = 'book'): Library
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);

        return Library::query()->create(['name' => 'Test', 'media_type' => $mediaType, 'owner_id' => $owner->id]);
    }

    public function test_deletes_a_file_not_referenced_by_any_media_item(): void
    {
        Storage::disk('local')->put('covers/book/orphan.jpg', 'orphaned bytes');
        $this->travel(61)->minutes(); // past GRACE_PERIOD_MINUTES — see its own test below for the boundary itself

        $deleted = app(CoverCleanupService::class)->cleanup();

        $this->assertSame(1, $deleted);
        Storage::disk('local')->assertMissing('covers/book/orphan.jpg');
    }

    public function test_keeps_a_file_referenced_by_a_media_items_cover_path(): void
    {
        Storage::disk('local')->put('covers/book/dune.jpg', 'referenced bytes');
        MediaBook::query()->create([
            'library_id' => $this->library()->id,
            'title' => 'Dune',
            'ean' => '9780000000001',
            'cover_path' => 'covers/book/dune.jpg',
        ]);

        $deleted = app(CoverCleanupService::class)->cleanup();

        $this->assertSame(0, $deleted);
        Storage::disk('local')->assertExists('covers/book/dune.jpg');
    }

    public function test_keeps_the_thumbnail_of_a_referenced_cover(): void
    {
        $service = app(CoverDownloadService::class);
        Storage::disk('local')->put('covers/book/dune.jpg', 'referenced bytes');
        $thumbnailPath = $service->thumbnailPath('covers/book/dune.jpg');
        Storage::disk('local')->put($thumbnailPath, 'thumbnail bytes');
        MediaBook::query()->create([
            'library_id' => $this->library()->id,
            'title' => 'Dune',
            'ean' => '9780000000001',
            'cover_path' => 'covers/book/dune.jpg',
        ]);

        $deleted = app(CoverCleanupService::class)->cleanup();

        $this->assertSame(0, $deleted);
        Storage::disk('local')->assertExists($thumbnailPath);
    }

    public function test_deletes_a_stray_thumbnail_whose_cover_is_no_longer_referenced(): void
    {
        $service = app(CoverDownloadService::class);
        Storage::disk('local')->put('covers/book/gone.jpg', 'bytes');
        Storage::disk('local')->put($service->thumbnailPath('covers/book/gone.jpg'), 'thumb bytes');
        // No media item references it at all.
        $this->travel(61)->minutes();

        $deleted = app(CoverCleanupService::class)->cleanup();

        $this->assertSame(2, $deleted);
    }

    public function test_checks_across_all_three_media_types(): void
    {
        Storage::disk('local')->put('covers/book/keep-book.jpg', 'x');
        Storage::disk('local')->put('covers/cd/keep-cd.jpg', 'x');
        Storage::disk('local')->put('covers/dvd_bluray/keep-dvd.jpg', 'x');
        Storage::disk('local')->put('covers/book/orphan-book.jpg', 'x');
        MediaBook::query()->create(['library_id' => $this->library('book')->id, 'title' => 'B', 'ean' => '1', 'cover_path' => 'covers/book/keep-book.jpg']);
        MediaCd::query()->create(['library_id' => $this->library('cd')->id, 'title' => 'C', 'ean' => '2', 'cover_path' => 'covers/cd/keep-cd.jpg']);
        MediaDvdBluray::query()->create(['library_id' => $this->library('dvd_bluray')->id, 'title' => 'D', 'ean' => '3', 'cover_path' => 'covers/dvd_bluray/keep-dvd.jpg']);
        $this->travel(61)->minutes();

        $deleted = app(CoverCleanupService::class)->cleanup();

        $this->assertSame(1, $deleted);
        Storage::disk('local')->assertExists('covers/book/keep-book.jpg');
        Storage::disk('local')->assertExists('covers/cd/keep-cd.jpg');
        Storage::disk('local')->assertExists('covers/dvd_bluray/keep-dvd.jpg');
        Storage::disk('local')->assertMissing('covers/book/orphan-book.jpg');
    }

    /**
     * GitHub-reported concern: CoverDownloadService::store() writes a cover
     * (and its thumbnail) to disk before the calling controller saves
     * `cover_path` on the media item — a freshly written, about-to-be-
     * referenced file must never be swept up as "orphaned" just because
     * cleanup() happened to run in that brief window. Storage::fake()'s
     * put() gives the file a real, current mtime, so with no time travel
     * this file is still well within the grace period by construction.
     */
    public function test_keeps_a_recently_written_unreferenced_file(): void
    {
        Storage::disk('local')->put('covers/book/just-captured.jpg', 'bytes');

        $deleted = app(CoverCleanupService::class)->cleanup();

        $this->assertSame(0, $deleted);
        Storage::disk('local')->assertExists('covers/book/just-captured.jpg');
    }

    /** Once genuinely past the grace period, an unreferenced file is still deleted as before — the grace period only defers, it doesn't exempt. */
    public function test_deletes_an_unreferenced_file_once_past_the_grace_period(): void
    {
        Storage::disk('local')->put('covers/book/abandoned.jpg', 'bytes');
        $this->travel(61)->minutes();

        $deleted = app(CoverCleanupService::class)->cleanup();

        $this->assertSame(1, $deleted);
        Storage::disk('local')->assertMissing('covers/book/abandoned.jpg');
    }

    public function test_handles_a_missing_covers_directory_gracefully(): void
    {
        // Nothing under covers/ at all — a fresh install that has never had a cover uploaded.
        $deleted = app(CoverCleanupService::class)->cleanup();

        $this->assertSame(0, $deleted);
    }

    public function test_logs_each_deleted_file_at_info_level(): void
    {
        Storage::disk('local')->put('covers/book/orphan.jpg', 'bytes');
        $this->travel(61)->minutes();
        Log::spy();

        app(CoverCleanupService::class)->cleanup();

        Log::shouldHaveReceived('info')->atLeast()->once()->with(
            'Deleted orphaned cover file.',
            Mockery::on(fn (array $context) => $context['path'] === 'covers/book/orphan.jpg')
        );
    }

    public function test_logs_a_summary_at_info_level(): void
    {
        Storage::disk('local')->put('covers/book/orphan.jpg', 'bytes');
        Storage::disk('local')->put('covers/book/kept.jpg', 'bytes');
        MediaBook::query()->create(['library_id' => $this->library()->id, 'title' => 'B', 'ean' => '1', 'cover_path' => 'covers/book/kept.jpg']);
        $this->travel(61)->minutes();
        Log::spy();

        app(CoverCleanupService::class)->cleanup();

        Log::shouldHaveReceived('info')->atLeast()->once()->with(
            'Cover cleanup complete.',
            Mockery::on(fn (array $context) => $context['checked'] === 2 && $context['deleted'] === 1)
        );
    }
}
