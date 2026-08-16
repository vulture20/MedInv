<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /libraries/{library}/items/bulk-update (GitHub issue #63) — sets one
 * field to one shared value across every selected item, the general
 * follow-up to bulk-delete (#54) that issue's own proposal text already
 * named as a possible next step. See MediaItemBulkDeleteTest for the
 * equivalent access-control/containment coverage this mirrors.
 */
class MediaItemBulkUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function createLibrary(int $ownerId, string $mediaType = 'book', string $name = 'Novels'): Library
    {
        return Library::query()->create(['name' => $name, 'media_type' => $mediaType, 'owner_id' => $ownerId]);
    }

    private function createBook(int $libraryId, string $ean, array $attributes = []): MediaBook
    {
        return MediaBook::query()->create(['library_id' => $libraryId, 'title' => 'Book '.$ean, 'ean' => $ean, ...$attributes]);
    }

    public function test_owner_can_bulk_update_a_field_across_several_items(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->createLibrary($owner->id);
        $a = $this->createBook($library->id, '9780000000001', ['genre' => 'Old genre']);
        $b = $this->createBook($library->id, '9780000000002', ['genre' => 'Old genre']);
        $c = $this->createBook($library->id, '9780000000003', ['genre' => 'Untouched']);

        $response = $this->postJson("/api/libraries/{$library->id}/items/bulk-update", [
            'ids' => [$a->id, $b->id],
            'field' => 'genre',
            'value' => 'Sci-Fi',
        ]);

        $response->assertOk();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $response->json('updated_ids'));
        $this->assertSame('Sci-Fi', $a->fresh()->genre);
        $this->assertSame('Sci-Fi', $b->fresh()->genre);
        $this->assertSame('Untouched', $c->fresh()->genre);
    }

    public function test_value_can_be_explicit_null_to_clear_a_field(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->createLibrary($owner->id);
        $item = $this->createBook($library->id, '9780000000001', ['genre' => 'Sci-Fi']);

        $response = $this->postJson("/api/libraries/{$library->id}/items/bulk-update", [
            'ids' => [$item->id],
            'field' => 'genre',
            'value' => null,
        ]);

        $response->assertOk();
        $this->assertNull($item->fresh()->genre);
    }

    /** GitHub issue #63's own explicit design choice: unlike update()'s "everything becomes optional" PUT rewrite, bulk-update keeps the target field's own 'required' rule intact. */
    public function test_a_required_field_cannot_be_bulk_cleared_to_empty(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->createLibrary($owner->id);
        $item = $this->createBook($library->id, '9780000000001');

        $response = $this->postJson("/api/libraries/{$library->id}/items/bulk-update", [
            'ids' => [$item->id],
            'field' => 'title',
            'value' => '',
        ]);

        $response->assertStatus(422);
        $this->assertSame('Book 9780000000001', $item->fresh()->title);
    }

    public function test_ean_is_not_a_bulk_editable_field(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->createLibrary($owner->id);
        $item = $this->createBook($library->id, '9780000000001');

        $response = $this->postJson("/api/libraries/{$library->id}/items/bulk-update", [
            'ids' => [$item->id],
            'field' => 'ean',
            'value' => '9780000000099',
        ]);

        $response->assertStatus(422);
    }

    /** GitHub issue #48: tracks isn't a single scalar value, and runtime_seconds/runtime_computed are only ever derived from it — none of the three are bulk-editable. */
    public function test_cd_tracks_and_runtime_fields_are_not_bulk_editable(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->createLibrary($owner->id, 'cd', 'CDs');
        $item = MediaCd::query()->create(['library_id' => $library->id, 'title' => 'OK Computer', 'ean' => '724385522925']);

        foreach (['tracks', 'runtime_seconds', 'runtime_computed'] as $field) {
            $response = $this->postJson("/api/libraries/{$library->id}/items/bulk-update", [
                'ids' => [$item->id],
                'field' => $field,
                'value' => $field === 'tracks' ? [] : 1,
            ]);

            $response->assertStatus(422);
        }
    }

    /** An id belonging to a *different* library must never be updatable by including it in this request — same containment bulkDestroy() already has. */
    public function test_bulk_update_never_touches_an_item_from_a_different_library(): void
    {
        $owner = $this->actingAsUser();
        $libraryA = $this->createLibrary($owner->id, 'book', 'Library A');
        $libraryB = $this->createLibrary($owner->id, 'book', 'Library B');
        $itemInA = $this->createBook($libraryA->id, '9780000000001');
        $itemInB = $this->createBook($libraryB->id, '9780000000002', ['genre' => 'Untouched']);

        $response = $this->postJson("/api/libraries/{$libraryA->id}/items/bulk-update", [
            'ids' => [$itemInA->id, $itemInB->id],
            'field' => 'genre',
            'value' => 'Sci-Fi',
        ]);

        $response->assertOk();
        $this->assertSame([$itemInA->id], $response->json('updated_ids'));
        $this->assertSame('Untouched', $itemInB->fresh()->genre);
    }

    public function test_bulk_update_requires_write_access(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = $this->createLibrary($owner->id);
        $item = $this->createBook($library->id, '9780000000001', ['genre' => 'Untouched']);
        $this->actingAsUser(); // a different, unrelated user

        $response = $this->postJson("/api/libraries/{$library->id}/items/bulk-update", [
            'ids' => [$item->id],
            'field' => 'genre',
            'value' => 'Sci-Fi',
        ]);

        $response->assertForbidden();
        $this->assertSame('Untouched', $item->fresh()->genre);
    }

    public function test_an_unknown_field_is_rejected(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->createLibrary($owner->id);
        $item = $this->createBook($library->id, '9780000000001');

        $response = $this->postJson("/api/libraries/{$library->id}/items/bulk-update", [
            'ids' => [$item->id],
            'field' => 'not_a_real_field',
            'value' => 'x',
        ]);

        $response->assertStatus(422);
    }
}
