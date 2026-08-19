<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #95: LibraryController::index() now reports each library's
 * item_count, for LibrariesPage.tsx's overview cards.
 */
class LibraryIndexItemCountTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_index_reports_the_correct_item_count_per_media_type(): void
    {
        $owner = $this->actingAsUser();
        $bookLibrary = Library::query()->create(['name' => 'Books', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $cdLibrary = Library::query()->create(['name' => 'CDs', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $bookLibrary->id, 'title' => 'A', 'ean' => '9780000000001']);
        MediaBook::query()->create(['library_id' => $bookLibrary->id, 'title' => 'B', 'ean' => '9780000000002']);
        MediaCd::query()->create(['library_id' => $cdLibrary->id, 'title' => 'C', 'ean' => '9780000000003']);

        $response = $this->getJson('/api/libraries');

        $response->assertOk();
        $libraries = collect($response->json());
        $this->assertSame(2, $libraries->firstWhere('id', $bookLibrary->id)['item_count']);
        $this->assertSame(1, $libraries->firstWhere('id', $cdLibrary->id)['item_count']);
    }

    public function test_an_empty_library_reports_a_zero_item_count(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Empty', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->getJson('/api/libraries');

        $response->assertOk();
        $this->assertSame(0, collect($response->json())->firstWhere('id', $library->id)['item_count']);
    }

    /** The three raw *_count columns withCount() adds internally are implementation detail, not meant to be part of the response. */
    public function test_the_raw_per_media_type_count_columns_are_not_exposed(): void
    {
        $owner = $this->actingAsUser();
        Library::query()->create(['name' => 'Books', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->getJson('/api/libraries');

        $response->assertOk();
        $keys = array_keys($response->json()[0]);
        $this->assertNotContains('media_books_count', $keys);
        $this->assertNotContains('media_cds_count', $keys);
        $this->assertNotContains('media_dvd_blurays_count', $keys);
    }
}
