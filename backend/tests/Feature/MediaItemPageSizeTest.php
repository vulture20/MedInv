<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #194: MediaItemController::index()'s per-page default is now
 * the requesting user's own `items_per_page` preference rather than a flat
 * 50 — an explicit `per_page` query param still wins either way (unchanged
 * behavior for any existing caller, see MediaItemListSortTest.php's own
 * requests, which never send one and still work).
 */
class MediaItemPageSizeTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(int $itemsPerPage = 50): User
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true, 'items_per_page' => $itemsPerPage]);
        $this->actingAs($user);

        return $user;
    }

    private function createLibraryWithItems(int $ownerId, int $count): Library
    {
        $library = Library::query()->create(['name' => 'Lib', 'media_type' => 'book', 'owner_id' => $ownerId]);
        for ($i = 0; $i < $count; $i++) {
            MediaBook::query()->create(['library_id' => $library->id, 'title' => "Book {$i}", 'ean' => sprintf('978%010d', $i)]);
        }

        return $library;
    }

    public function test_the_page_size_defaults_to_the_users_stored_preference(): void
    {
        $user = $this->actingAsUser(itemsPerPage: 20);
        $library = $this->createLibraryWithItems($user->id, 25);

        $response = $this->getJson("/api/libraries/{$library->id}/items");

        $response->assertOk();
        $this->assertCount(20, $response->json('data'));
        $this->assertSame(2, $response->json('last_page'));
    }

    public function test_an_explicit_per_page_query_param_still_wins_over_the_preference(): void
    {
        $user = $this->actingAsUser(itemsPerPage: 20);
        $library = $this->createLibraryWithItems($user->id, 25);

        $response = $this->getJson("/api/libraries/{$library->id}/items?per_page=100");

        $response->assertOk();
        $this->assertCount(25, $response->json('data'));
        $this->assertSame(1, $response->json('last_page'));
    }

    public function test_a_fresh_users_default_of_50_still_works_as_before(): void
    {
        $user = $this->actingAsUser();
        $library = $this->createLibraryWithItems($user->id, 3);

        $response = $this->getJson("/api/libraries/{$library->id}/items");

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }
}
