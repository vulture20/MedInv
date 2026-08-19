<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /libraries/{library}/items?sort_by=&sort_dir= (GitHub issue #77) — the
 * item-list table's column sorting. Server-side rather than sorting only the
 * current page client-side, since MediaItemController::index() is already
 * paginated (briefing 5.) and a client-side sort would silently only
 * reorder whichever 50 rows happened to be on the current page.
 */
class MediaItemListSortTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): User
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function createLibrary(int $ownerId, string $mediaType = 'book'): Library
    {
        return Library::query()->create(['name' => 'Lib', 'media_type' => $mediaType, 'owner_id' => $ownerId]);
    }

    public function test_sorts_book_items_by_title_ascending(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->createLibrary($owner->id);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Charlie', 'ean' => '9780000000003']);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Alpha', 'ean' => '9780000000001']);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Bravo', 'ean' => '9780000000002']);

        $response = $this->getJson("/api/libraries/{$library->id}/items?sort_by=title&sort_dir=asc");

        $response->assertOk();
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], array_column($response->json('data'), 'title'));
    }

    public function test_sorts_descending_when_requested(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->createLibrary($owner->id);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Charlie', 'ean' => '9780000000003']);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Alpha', 'ean' => '9780000000001']);

        $response = $this->getJson("/api/libraries/{$library->id}/items?sort_by=title&sort_dir=desc");

        $response->assertOk();
        $this->assertSame(['Charlie', 'Alpha'], array_column($response->json('data'), 'title'));
    }

    public function test_sorts_by_the_media_types_own_subtitle_column(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->createLibrary($owner->id, 'cd');
        MediaCd::query()->create(['library_id' => $library->id, 'title' => 'X', 'ean' => '9780000000001', 'artist' => 'Zeta']);
        MediaCd::query()->create(['library_id' => $library->id, 'title' => 'Y', 'ean' => '9780000000002', 'artist' => 'Anna']);

        $response = $this->getJson("/api/libraries/{$library->id}/items?sort_by=artist&sort_dir=asc");

        $response->assertOk();
        $this->assertSame(['Anna', 'Zeta'], array_column($response->json('data'), 'artist'));
    }

    /** GitHub issue #108 — location (issue #96) is sortable for every media type, unlike authors/artist/director, which are each specific to one. */
    public function test_sorts_by_location_regardless_of_media_type(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->createLibrary($owner->id, 'book');
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'X', 'ean' => '9780000000001', 'location' => 'Regal 5']);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Y', 'ean' => '9780000000002', 'location' => 'Regal 1']);

        $response = $this->getJson("/api/libraries/{$library->id}/items?sort_by=location&sort_dir=asc");

        $response->assertOk();
        $this->assertSame(['Regal 1', 'Regal 5'], array_column($response->json('data'), 'location'));
    }

    /** `authors`/`director` are only sortable for the media type they actually belong to — mirrors FIELD_SPECS' own per-type field sets. */
    public function test_rejects_a_sort_column_that_does_not_belong_to_this_media_type(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->createLibrary($owner->id, 'book');
        $a = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Charlie', 'ean' => '9780000000003']);
        $b = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Alpha', 'ean' => '9780000000001']);

        // 'artist' isn't a book column — must not 500 (an invalid orderBy() column), just falls back to unsorted.
        $response = $this->getJson("/api/libraries/{$library->id}/items?sort_by=artist&sort_dir=asc");

        $response->assertOk();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($response->json('data'), 'id'));
    }

    public function test_unsorted_request_still_works_as_before(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->createLibrary($owner->id);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Solo', 'ean' => '9780000000001']);

        $this->getJson("/api/libraries/{$library->id}/items")->assertOk()->assertJsonCount(1, 'data');
    }
}
