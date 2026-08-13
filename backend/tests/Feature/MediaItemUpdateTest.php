<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MediaItemController::update(). Added alongside the media item detail
 * dialog's edit form: PUTting an explicit `null` for an optional field
 * (e.g. clearing "Description") used to 422 with "must be a string" —
 * the rule-rewriting for PUT (`array_slice($rule, 1)`) positionally
 * dropped whichever validation rule happened to be listed first, which for
 * every field except `title` is `nullable` itself, so it got silently
 * stripped. No prior UI ever sent an explicit null for these fields, so
 * this had gone unnoticed until confirmed live against a running dev
 * server while building that dialog.
 */
class MediaItemUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_an_optional_field_can_be_cleared_by_sending_an_explicit_null(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create([
            'library_id' => $library->id,
            'title' => 'Dune',
            'ean' => '9780000000001',
            'description' => 'A desert planet epic.',
            'genre' => 'Sci-Fi',
        ]);

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", [
            'description' => null,
            'genre' => null,
        ]);

        $response->assertOk();
        $this->assertNull($item->fresh()->description);
        $this->assertNull($item->fresh()->genre);
    }

    public function test_fields_absent_from_the_request_are_left_untouched(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create([
            'library_id' => $library->id,
            'title' => 'Dune',
            'ean' => '9780000000001',
            'authors' => 'Frank Herbert',
        ]);

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['title' => 'Dune (Deluxe Edition)']);

        $response->assertOk();
        $this->assertSame('Dune (Deluxe Edition)', $item->fresh()->title);
        $this->assertSame('Frank Herbert', $item->fresh()->authors);
    }

    public function test_ean_cannot_be_changed_via_update(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['ean' => '9780000000099']);

        $response->assertOk();
        $this->assertSame('9780000000001', $item->fresh()->ean);
    }

    public function test_a_number_field_can_also_be_cleared_via_null(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'page_count' => 412]);

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['page_count' => null]);

        $response->assertOk();
        $this->assertNull($item->fresh()->page_count);
    }

    public function test_users_without_write_access_cannot_update(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $this->actingAsUser(); // a different, unrelated user — library is not shared with them

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['title' => 'Hijacked']);

        $response->assertForbidden();
        $this->assertSame('Dune', $item->fresh()->title);
    }
}
