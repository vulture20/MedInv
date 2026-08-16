<?php

namespace Tests\Feature;

use App\Domain\Metadata\CoverDownloadService;
use App\Models\Library;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * POST /libraries/{library}/items/bulk-delete (GitHub issue #54) — the
 * multi-select counterpart to MediaItemController::destroy(), same write
 * access check and per-item cover/thumbnail cleanup (see
 * MediaItemCoverUploadTest for the equivalent single-item coverage this
 * mirrors), just over a list of ids instead of one.
 */
class MediaItemBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function createLibrary(int $ownerId, string $name = 'Novels'): Library
    {
        return Library::query()->create(['name' => $name, 'media_type' => 'book', 'owner_id' => $ownerId]);
    }

    private function createBook(int $libraryId, string $ean, array $attributes = []): MediaBook
    {
        return MediaBook::query()->create(['library_id' => $libraryId, 'title' => 'Book '.$ean, 'ean' => $ean, ...$attributes]);
    }

    public function test_owner_can_bulk_delete_several_items_at_once(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->createLibrary($owner->id);
        $a = $this->createBook($library->id, '9780000000001');
        $b = $this->createBook($library->id, '9780000000002');
        $c = $this->createBook($library->id, '9780000000003');

        $response = $this->postJson("/api/libraries/{$library->id}/items/bulk-delete", ['ids' => [$a->id, $b->id]]);

        $response->assertOk();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $response->json('deleted_ids'));
        $this->assertDatabaseMissing((new MediaBook)->getTable(), ['id' => $a->id]);
        $this->assertDatabaseMissing((new MediaBook)->getTable(), ['id' => $b->id]);
        $this->assertDatabaseHas((new MediaBook)->getTable(), ['id' => $c->id]);
    }

    public function test_bulk_delete_cleans_up_cover_and_thumbnail_files(): void
    {
        Storage::fake('local');
        $owner = $this->actingAsUser();
        $library = $this->createLibrary($owner->id);
        $coverService = app(CoverDownloadService::class);
        $item = $this->createBook($library->id, '9780000000001');
        $path = $coverService->uploadFromFile(UploadedFile::fake()->image('cover.jpg'), 'book', $item->ean);
        $item->update(['cover_path' => $path]);
        $thumbnailPath = $coverService->thumbnailPath($path);
        Storage::disk('local')->assertExists($path);

        $this->postJson("/api/libraries/{$library->id}/items/bulk-delete", ['ids' => [$item->id]])->assertOk();

        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertMissing($thumbnailPath);
    }

    /** An id belonging to a *different* library must never be deletable by including it in this request — same containment destroy()'s findOrFail() already gets from the same relation. */
    public function test_bulk_delete_never_touches_an_item_from_a_different_library(): void
    {
        $owner = $this->actingAsUser();
        $libraryA = $this->createLibrary($owner->id, 'Library A');
        $libraryB = $this->createLibrary($owner->id, 'Library B');
        $itemInA = $this->createBook($libraryA->id, '9780000000001');
        $itemInB = $this->createBook($libraryB->id, '9780000000002');

        $response = $this->postJson("/api/libraries/{$libraryA->id}/items/bulk-delete", ['ids' => [$itemInA->id, $itemInB->id]]);

        $response->assertOk();
        $this->assertSame([$itemInA->id], $response->json('deleted_ids'));
        $this->assertDatabaseHas((new MediaBook)->getTable(), ['id' => $itemInB->id]);
    }

    /** A stale/already-deleted/typo'd id is silently skipped rather than 404ing the whole request — see bulkDestroy()'s docblock. */
    public function test_an_id_that_does_not_exist_is_silently_skipped(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->createLibrary($owner->id);
        $item = $this->createBook($library->id, '9780000000001');

        $response = $this->postJson("/api/libraries/{$library->id}/items/bulk-delete", ['ids' => [$item->id, 999999]]);

        $response->assertOk();
        $this->assertSame([$item->id], $response->json('deleted_ids'));
    }

    public function test_bulk_delete_requires_write_access(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = $this->createLibrary($owner->id);
        $item = $this->createBook($library->id, '9780000000001');
        $this->actingAsUser(); // a different, unrelated user

        $response = $this->postJson("/api/libraries/{$library->id}/items/bulk-delete", ['ids' => [$item->id]]);

        $response->assertForbidden();
        $this->assertDatabaseHas((new MediaBook)->getTable(), ['id' => $item->id]);
    }

    public function test_an_empty_ids_list_is_rejected(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->createLibrary($owner->id);

        $response = $this->postJson("/api/libraries/{$library->id}/items/bulk-delete", ['ids' => []]);

        $response->assertStatus(422);
    }
}
