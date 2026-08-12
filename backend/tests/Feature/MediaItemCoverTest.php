<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** GitHub issue #6: serving a stored cover back (MediaItemController::cover()). */
class MediaItemCoverTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_returns_the_stored_cover_for_an_authorized_reader(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('covers/book/dune.jpg', 'fake-jpeg-bytes');
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create([
            'library_id' => $library->id,
            'title' => 'Dune',
            'ean' => '9780000000001',
            'cover_path' => 'covers/book/dune.jpg',
        ]);

        $response = $this->get("/api/libraries/{$library->id}/items/{$item->id}/cover");

        $response->assertOk();
        $this->assertSame('fake-jpeg-bytes', $response->streamedContent());
    }

    public function test_returns_404_when_the_item_has_no_cover(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);

        $response = $this->get("/api/libraries/{$library->id}/items/{$item->id}/cover");

        $response->assertNotFound();
    }

    public function test_returns_403_for_an_unshared_library(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('covers/book/dune.jpg', 'fake-jpeg-bytes');
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create([
            'library_id' => $library->id,
            'title' => 'Dune',
            'ean' => '9780000000001',
            'cover_path' => 'covers/book/dune.jpg',
        ]);
        $this->actingAsUser(); // a different, unrelated user — library is not shared with them

        $response = $this->get("/api/libraries/{$library->id}/items/{$item->id}/cover");

        $response->assertForbidden();
    }
}
