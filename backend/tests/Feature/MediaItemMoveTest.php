<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MediaItemController::move() — the media item detail dialog's "move to
 * another library" action. Requires write access to both libraries,
 * restricts targets to the same media_type (MediaItemService::move()'s
 * docblock explains why), and re-applies the per-library duplicate-EAN rule
 * (briefing 5.1) at the destination.
 */
class MediaItemMoveTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_owner_can_move_an_item_between_their_own_libraries(): void
    {
        $owner = $this->actingAsUser();
        $source = Library::query()->create(['name' => 'Source', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $target = Library::query()->create(['name' => 'Target', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $source->id, 'title' => 'Dune', 'ean' => '9780000000001']);

        $response = $this->postJson("/api/libraries/{$source->id}/items/{$item->id}/move", ['target_library_id' => $target->id]);

        $response->assertOk();
        $this->assertSame($target->id, $item->fresh()->library_id);
    }

    public function test_admin_can_move_an_item_between_libraries_they_do_not_own(): void
    {
        $this->actingAsUser('admin');
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $source = Library::query()->create(['name' => 'Source', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $target = Library::query()->create(['name' => 'Target', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $source->id, 'title' => 'Dune', 'ean' => '9780000000001']);

        $response = $this->postJson("/api/libraries/{$source->id}/items/{$item->id}/move", ['target_library_id' => $target->id]);

        $response->assertOk();
        $this->assertSame($target->id, $item->fresh()->library_id);
    }

    public function test_rejects_moving_out_of_a_library_the_user_cannot_write_to(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $source = Library::query()->create(['name' => 'Source', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $source->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $mover = $this->actingAsUser();
        $target = Library::query()->create(['name' => 'Target', 'media_type' => 'book', 'owner_id' => $mover->id]);

        $response = $this->postJson("/api/libraries/{$source->id}/items/{$item->id}/move", ['target_library_id' => $target->id]);

        $response->assertForbidden();
        $this->assertSame($source->id, $item->fresh()->library_id);
    }

    public function test_rejects_moving_into_a_library_the_user_cannot_write_to(): void
    {
        $owner = $this->actingAsUser();
        $source = Library::query()->create(['name' => 'Source', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $source->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $stranger = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $target = Library::query()->create(['name' => 'Target', 'media_type' => 'book', 'owner_id' => $stranger->id]);

        $response = $this->postJson("/api/libraries/{$source->id}/items/{$item->id}/move", ['target_library_id' => $target->id]);

        $response->assertForbidden();
        $this->assertSame($source->id, $item->fresh()->library_id);
    }

    public function test_rejects_moving_into_a_library_of_a_different_media_type(): void
    {
        $owner = $this->actingAsUser();
        $source = Library::query()->create(['name' => 'Books', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $target = Library::query()->create(['name' => 'CDs', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $source->id, 'title' => 'Dune', 'ean' => '9780000000001']);

        $response = $this->postJson("/api/libraries/{$source->id}/items/{$item->id}/move", ['target_library_id' => $target->id]);

        $response->assertStatus(422);
        $response->assertJson(['error_code' => 'media_type_mismatch']);
        $this->assertSame($source->id, $item->fresh()->library_id);
    }

    public function test_rejects_moving_into_the_same_library(): void
    {
        $owner = $this->actingAsUser();
        $source = Library::query()->create(['name' => 'Source', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $source->id, 'title' => 'Dune', 'ean' => '9780000000001']);

        $response = $this->postJson("/api/libraries/{$source->id}/items/{$item->id}/move", ['target_library_id' => $source->id]);

        $response->assertStatus(422);
        $response->assertJson(['error_code' => 'same_library']);
    }

    public function test_rejects_moving_when_the_target_already_has_the_same_ean(): void
    {
        $owner = $this->actingAsUser();
        $source = Library::query()->create(['name' => 'Source', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $target = Library::query()->create(['name' => 'Target', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $source->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        MediaBook::query()->create(['library_id' => $target->id, 'title' => 'Dune (other copy)', 'ean' => '9780000000001']);

        $response = $this->postJson("/api/libraries/{$source->id}/items/{$item->id}/move", ['target_library_id' => $target->id]);

        $response->assertStatus(409);
        $this->assertSame($source->id, $item->fresh()->library_id);
    }

    /** Sanity check that MediaItemService::modelClassFor() resolution is actually exercised end-to-end for a non-book type too. */
    public function test_moving_a_cd_item_works_the_same_way(): void
    {
        $owner = $this->actingAsUser();
        $source = Library::query()->create(['name' => 'Source', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        $target = Library::query()->create(['name' => 'Target', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        $item = MediaCd::query()->create(['library_id' => $source->id, 'title' => 'OK Computer', 'ean' => '9780000000002']);

        $response = $this->postJson("/api/libraries/{$source->id}/items/{$item->id}/move", ['target_library_id' => $target->id]);

        $response->assertOk();
        $this->assertSame($target->id, $item->fresh()->library_id);
    }

    public function test_guests_cannot_move_items(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $source = Library::query()->create(['name' => 'Source', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $target = Library::query()->create(['name' => 'Target', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $source->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $this->actingAsUser('guest');

        $response = $this->postJson("/api/libraries/{$source->id}/items/{$item->id}/move", ['target_library_id' => $target->id]);

        $response->assertForbidden();
    }
}
