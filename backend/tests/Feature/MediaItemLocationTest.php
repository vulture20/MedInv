<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #96: `location` (e.g. "Regal 3, Fach 2") — a deliberate
 * extension beyond briefing 6.1-6.3's fixed attribute set, free text and
 * not validated against a fixed list, the same "records whatever the user
 * says" stance `currency` (#58) already takes. Exercised here only through
 * MediaBook/book, same as MediaItemCurrencyTest — MediaCd/MediaDvdBluray
 * share the exact same rulesFor()/#[Fillable] pattern, no media-type-
 * specific behavior to cover separately.
 */
class MediaItemLocationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_location_can_be_set_on_manual_creation(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->postJson("/api/libraries/{$library->id}/items", [
            'title' => 'Dune',
            'ean' => '9780000000001',
            'location' => 'Regal 3, Fach 2',
        ]);

        $response->assertCreated();
        $this->assertSame('Regal 3, Fach 2', $response->json('location'));
        $this->assertDatabaseHas((new MediaBook)->getTable(), ['ean' => '9780000000001', 'location' => 'Regal 3, Fach 2']);
    }

    public function test_location_can_be_updated_and_cleared(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create([
            'library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'location' => 'Keller',
        ]);

        $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['location' => 'Dachboden'])->assertOk();
        $this->assertSame('Dachboden', $item->fresh()->location);

        $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['location' => null])->assertOk();
        $this->assertNull($item->fresh()->location);
    }

    public function test_location_can_be_set_via_bulk_update(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $a = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'A', 'ean' => '9780000000001']);
        $b = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'B', 'ean' => '9780000000002']);

        $response = $this->postJson("/api/libraries/{$library->id}/items/bulk-update", [
            'ids' => [$a->id, $b->id],
            'field' => 'location',
            'value' => 'Regal 5',
        ]);

        $response->assertOk();
        $this->assertSame('Regal 5', $a->fresh()->location);
        $this->assertSame('Regal 5', $b->fresh()->location);
    }

    public function test_an_item_is_findable_by_its_location(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create([
            'library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'location' => 'KellerBoxUnique8213',
        ]);

        $response = $this->getJson('/api/search?query=KellerBoxUnique8213');

        $response->assertOk();
        $this->assertTrue(collect($response->json())->contains('id', $item->id));
    }
}
