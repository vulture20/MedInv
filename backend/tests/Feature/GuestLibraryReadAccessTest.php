<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\LibraryShare;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #38: the media-item read routes (index/show/cover/
 * cover/thumbnail) used to sit inside the same `level:user,admin`
 * middleware group as the write routes, so `EnsureUserHasLevel` rejected
 * every guest with a blanket 403 before the request ever reached
 * MediaItemController's own, already-correct `canRead()` check — making a
 * library explicitly shared with guests (briefing 4.2/4.3) visible in the
 * library list but never actually readable. These routes were moved out of
 * that group in routes/api.php; this covers both the fix (a guest with a
 * matching share can read) and that guests still can't write.
 */
class GuestLibraryReadAccessTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function sharedLibrary(): array
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        LibraryShare::query()->create(['library_id' => $library->id, 'scope' => 'guest']);
        $item = MediaBook::query()->create([
            'library_id' => $library->id,
            'title' => 'Dune',
            'ean' => '9780000000001',
        ]);

        return [$library, $item];
    }

    public function test_a_guest_with_a_guest_share_can_list_the_librarys_items(): void
    {
        [$library] = $this->sharedLibrary();
        $this->actingAsUser('guest');

        $response = $this->getJson("/api/libraries/{$library->id}/items");

        $response->assertOk();
    }

    public function test_a_guest_with_a_guest_share_can_view_a_single_item(): void
    {
        [$library, $item] = $this->sharedLibrary();
        $this->actingAsUser('guest');

        $response = $this->getJson("/api/libraries/{$library->id}/items/{$item->id}");

        $response->assertOk();
        $response->assertJsonPath('title', 'Dune');
    }

    public function test_a_guest_without_any_share_still_cannot_list_the_librarys_items(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Private stash', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $this->actingAsUser('guest');

        $response = $this->getJson("/api/libraries/{$library->id}/items");

        $response->assertForbidden();
    }

    public function test_a_guest_still_cannot_create_an_item_in_a_shared_library(): void
    {
        [$library] = $this->sharedLibrary();
        $this->actingAsUser('guest');

        $response = $this->postJson("/api/libraries/{$library->id}/items", [
            'title' => 'New book',
            'ean' => '9780000000099',
        ]);

        $response->assertForbidden();
    }

    public function test_a_guest_with_a_guest_share_can_reach_the_cover_endpoint(): void
    {
        // Mirrors MediaItemCoverTest::test_returns_404_when_the_item_has_no_cover():
        // the item has no cover_path, so a 404 here (not 403) proves canRead()
        // passed and the level middleware is no longer what's blocking guests.
        [$library, $item] = $this->sharedLibrary();
        $this->actingAsUser('guest');

        $response = $this->getJson("/api/libraries/{$library->id}/items/{$item->id}/cover");

        $response->assertNotFound();
    }
}
