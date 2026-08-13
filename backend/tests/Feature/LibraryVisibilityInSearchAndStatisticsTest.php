<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\LibraryShare;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #33: LibraryAccessService's own docblock states the rule
 * explicitly — "Unshared libraries are invisible to non-owners/non-admins,
 * including in search results (4.3: weder sichtbar noch auffindbar)" — but
 * neither SearchTest nor StatisticsTest actually exercised this. Confirms
 * SearchService/StatisticsService (both built on
 * LibraryAccessService::visibleLibrariesQuery()) enforce it end-to-end
 * through the real HTTP endpoints, not just that the service method itself
 * returns the right query (LibraryAccessServiceTest).
 */
class LibraryVisibilityInSearchAndStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_an_unshared_librarys_items_do_not_appear_in_search_results(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Secret Stash', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'FindMeNot9284', 'ean' => '9780000000001']);
        $this->actingAsUser();

        $response = $this->getJson('/api/search?query=FindMeNot9284');

        $response->assertOk();
        $this->assertFalse(collect($response->json())->contains('id', $item->id));
    }

    public function test_a_shared_librarys_items_do_appear_in_search_results(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Shared Stash', 'media_type' => 'book', 'owner_id' => $owner->id]);
        LibraryShare::query()->create(['library_id' => $library->id, 'scope' => 'all_users']);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'FindMeNow7361', 'ean' => '9780000000002']);
        $this->actingAsUser();

        $response = $this->getJson('/api/search?query=FindMeNow7361');

        $response->assertOk();
        $this->assertTrue(collect($response->json())->contains('id', $item->id));
    }

    public function test_an_unshared_library_does_not_appear_in_statistics(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Secret Stash', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $this->actingAsUser();

        $response = $this->getJson('/api/statistics');

        $response->assertOk();
        $this->assertFalse(collect($response->json())->contains('library_id', $library->id));
    }

    public function test_a_shared_library_does_appear_in_statistics(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Shared Stash', 'media_type' => 'book', 'owner_id' => $owner->id]);
        LibraryShare::query()->create(['library_id' => $library->id, 'scope' => 'all_users']);
        $this->actingAsUser();

        $response = $this->getJson('/api/statistics');

        $response->assertOk();
        $this->assertTrue(collect($response->json())->contains('library_id', $library->id));
    }

    public function test_an_admin_sees_every_librarys_items_in_search_regardless_of_shares(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Secret Stash', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'AdminSeesAll4471', 'ean' => '9780000000003']);
        $this->actingAsUser('admin');

        $response = $this->getJson('/api/search?query=AdminSeesAll4471');

        $response->assertOk();
        $this->assertTrue(collect($response->json())->contains('id', $item->id));
    }
}
