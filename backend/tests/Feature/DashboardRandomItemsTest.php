<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\LibraryShare;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\MediaDvdBluray;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #116 — GET /dashboard/random-items feeds DashboardPage.tsx's
 * three cover carousels (CD/Buch/DVD-Blu-ray). Same visibility-scoping
 * reasoning already covered end-to-end for search/statistics by
 * LibraryVisibilityInSearchAndStatisticsTest — this file focuses on what's
 * specific to this endpoint instead: the response is keyed by media type,
 * each item carries its library, and the result is capped at 25.
 */
class DashboardRandomItemsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_the_response_is_keyed_by_media_type(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/dashboard/random-items');

        $response->assertOk();
        $response->assertJsonStructure(['book', 'cd', 'dvd_bluray']);
    }

    public function test_an_item_carries_its_owning_library(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        LibraryShare::query()->create(['library_id' => $library->id, 'scope' => 'all_users']);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $this->actingAsUser();

        $response = $this->getJson('/api/dashboard/random-items');

        $response->assertOk();
        $hit = collect($response->json('book'))->firstWhere('id', $item->id);
        $this->assertNotNull($hit);
        $this->assertSame($library->id, $hit['library']['id']);
        $this->assertSame($owner->id, $hit['library']['owner']['id']);
    }

    /** Same rule as search/statistics (LibraryVisibilityInSearchAndStatisticsTest): an unshared library's items must not appear here either. */
    public function test_an_unshared_librarys_items_are_excluded(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Secret Stash', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        $item = MediaCd::query()->create(['library_id' => $library->id, 'title' => 'Hidden Album', 'ean' => '9780000000002']);
        $this->actingAsUser();

        $response = $this->getJson('/api/dashboard/random-items');

        $response->assertOk();
        $this->assertFalse(collect($response->json('cd'))->contains('id', $item->id));
    }

    public function test_an_admin_sees_every_librarys_items_regardless_of_shares(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Secret Stash', 'media_type' => 'dvd_bluray', 'owner_id' => $owner->id]);
        $item = MediaDvdBluray::query()->create(['library_id' => $library->id, 'title' => 'Frankenstein', 'ean' => '9780000000003']);
        $this->actingAsUser('admin');

        $response = $this->getJson('/api/dashboard/random-items');

        $response->assertOk();
        $this->assertTrue(collect($response->json('dvd_bluray'))->contains('id', $item->id));
    }

    public function test_the_result_is_capped_at_twenty_five_items_per_media_type(): void
    {
        $owner = $this->actingAsUser('admin');
        $library = Library::query()->create(['name' => 'Big Shelf', 'media_type' => 'book', 'owner_id' => $owner->id]);
        for ($i = 0; $i < 30; $i++) {
            MediaBook::query()->create(['library_id' => $library->id, 'title' => "Book {$i}", 'ean' => sprintf('978000000%04d', $i)]);
        }

        $response = $this->getJson('/api/dashboard/random-items');

        $response->assertOk();
        $this->assertCount(25, $response->json('book'));
    }

    /** A media type with nothing visible still returns an (empty) key rather than omitting it — DashboardPage.tsx always renders all three panels. */
    public function test_a_media_type_with_no_visible_items_returns_an_empty_list(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/dashboard/random-items');

        $response->assertOk();
        $this->assertSame([], $response->json('cd'));
    }

    public function test_a_guest_can_see_items_from_a_library_explicitly_shared_with_guests(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Shared With Guests', 'media_type' => 'book', 'owner_id' => $owner->id]);
        LibraryShare::query()->create(['library_id' => $library->id, 'scope' => 'guest']);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'GuestVisible', 'ean' => '9780000000004']);
        $this->actingAsUser('guest');

        $response = $this->getJson('/api/dashboard/random-items');

        $response->assertOk();
        $this->assertTrue(collect($response->json('book'))->contains('id', $item->id));
    }
}
