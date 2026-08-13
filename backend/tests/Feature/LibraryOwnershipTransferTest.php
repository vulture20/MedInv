<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\LibraryShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PUT /libraries/{library}/owner (LibraryController::transferOwnership()),
 * GitHub issue #34: lets a library's ownership move to another account —
 * the missing piece that used to leave "the library gets destroyed along
 * with its owner's account" as the only option (see
 * UserDeletionOwnedLibrariesTest for the other half of that fix).
 */
class LibraryOwnershipTransferTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function library(int $ownerId): Library
    {
        return Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $ownerId]);
    }

    public function test_owner_can_transfer_ownership_to_another_user(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);
        $newOwner = User::factory()->create(['level' => 'user', 'is_active' => true]);

        $response = $this->putJson("/api/libraries/{$library->id}/owner", ['owner_id' => $newOwner->id]);

        $response->assertOk();
        $response->assertJsonPath('owner.id', $newOwner->id);
        $this->assertSame($newOwner->id, $library->fresh()->owner_id);
    }

    public function test_admin_can_transfer_ownership_of_a_library_they_do_not_own(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = $this->library($owner->id);
        $newOwner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAsUser('admin');

        $response = $this->putJson("/api/libraries/{$library->id}/owner", ['owner_id' => $newOwner->id]);

        $response->assertOk();
        $this->assertSame($newOwner->id, $library->fresh()->owner_id);
    }

    public function test_a_non_owner_non_admin_cannot_transfer_ownership(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = $this->library($owner->id);
        $newOwner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAsUser(); // a different, unrelated user

        $response = $this->putJson("/api/libraries/{$library->id}/owner", ['owner_id' => $newOwner->id]);

        $response->assertForbidden();
        $this->assertSame($owner->id, $library->fresh()->owner_id);
    }

    public function test_a_guest_cannot_transfer_ownership(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = $this->library($owner->id);
        $newOwner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAsUser('guest');

        $response = $this->putJson("/api/libraries/{$library->id}/owner", ['owner_id' => $newOwner->id]);

        $response->assertForbidden();
    }

    public function test_transferring_to_a_nonexistent_user_is_rejected(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);

        $response = $this->putJson("/api/libraries/{$library->id}/owner", ['owner_id' => 999999]);

        $response->assertStatus(422);
        $this->assertSame($owner->id, $library->fresh()->owner_id);
    }

    public function test_transferring_to_a_guest_level_account_is_rejected(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);
        $guest = User::factory()->create(['level' => 'guest', 'is_active' => true]);

        $response = $this->putJson("/api/libraries/{$library->id}/owner", ['owner_id' => $guest->id]);

        $response->assertStatus(422);
        $this->assertSame($owner->id, $library->fresh()->owner_id);
    }

    public function test_transferring_ownership_leaves_existing_shares_untouched(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);
        LibraryShare::query()->create(['library_id' => $library->id, 'scope' => 'all_users']);
        $newOwner = User::factory()->create(['level' => 'user', 'is_active' => true]);

        $response = $this->putJson("/api/libraries/{$library->id}/owner", ['owner_id' => $newOwner->id]);

        $response->assertOk();
        $this->assertDatabaseHas(
            (new LibraryShare)->getTable(),
            ['library_id' => $library->id, 'scope' => 'all_users']
        );
    }
}
