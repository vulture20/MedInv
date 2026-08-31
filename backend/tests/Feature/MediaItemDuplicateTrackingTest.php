<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #208: "Dubletten vorhanden" checkbox + count — a deliberate
 * extension beyond briefing 6.1-6.3's fixed attribute set, the same kind
 * `location` (#96) already is. Exercised here only through MediaBook/book,
 * same as MediaItemLocationTest — MediaCd/MediaDvdBluray share the exact
 * same rulesFor()/#[Fillable] pattern, no media-type-specific behavior to
 * cover separately.
 */
class MediaItemDuplicateTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_has_duplicates_defaults_to_false_and_duplicate_count_to_null(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->postJson("/api/libraries/{$library->id}/items", [
            'title' => 'Dune',
            'ean' => '9780000000001',
        ]);

        $response->assertCreated();
        $this->assertFalse($response->json('has_duplicates'));
        $this->assertNull($response->json('duplicate_count'));
    }

    public function test_duplicate_count_can_be_set_alongside_has_duplicates_on_manual_creation(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->postJson("/api/libraries/{$library->id}/items", [
            'title' => 'Dune',
            'ean' => '9780000000001',
            'has_duplicates' => true,
            'duplicate_count' => 3,
        ]);

        $response->assertCreated();
        $this->assertTrue($response->json('has_duplicates'));
        $this->assertSame(3, $response->json('duplicate_count'));
        $this->assertDatabaseHas((new MediaBook)->getTable(), ['ean' => '9780000000001', 'has_duplicates' => true, 'duplicate_count' => 3]);
    }

    public function test_duplicate_count_is_required_when_has_duplicates_is_true_on_creation(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->postJson("/api/libraries/{$library->id}/items", [
            'title' => 'Dune',
            'ean' => '9780000000001',
            'has_duplicates' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['duplicate_count']);
    }

    public function test_has_duplicates_and_duplicate_count_can_be_updated(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create([
            'library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001',
        ]);

        $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", [
            'has_duplicates' => true,
            'duplicate_count' => 2,
        ])->assertOk();

        $item->refresh();
        $this->assertTrue($item->has_duplicates);
        $this->assertSame(2, $item->duplicate_count);
    }

    public function test_duplicate_count_can_be_cleared_by_unsetting_has_duplicates(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create([
            'library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001',
            'has_duplicates' => true, 'duplicate_count' => 5,
        ]);

        $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", [
            'has_duplicates' => false,
            'duplicate_count' => null,
        ])->assertOk();

        $item->refresh();
        $this->assertFalse($item->has_duplicates);
        $this->assertNull($item->duplicate_count);
    }
}
