<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AccountSettingsController::destroy() (GitHub issue #86): a user can now
 * remove their own account without needing an admin to do it for them.
 * Enforces the exact same two rules as the admin-initiated path
 * (UserController::destroy(), see UserDeletionOwnedLibrariesTest) via the
 * shared UserDeletionService — this file focuses on what's specific to the
 * self-service entry point (no {user} route parameter, reachable by any
 * level including guest, logs out the deleting session).
 */
class AccountSelfDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_a_user_can_delete_their_own_account(): void
    {
        $user = $this->actingAsUser();

        $response = $this->withHeaders(['Origin' => 'http://localhost:5173'])->withSession([])->deleteJson('/api/me');

        $response->assertNoContent();
        $this->assertDatabaseMissing((new User)->getTable(), ['id' => $user->id]);
    }

    /** Reachable by any level, not just 'user'/'admin' — a guest has just as much right to remove their own account. */
    public function test_a_guest_can_delete_their_own_account(): void
    {
        $guest = $this->actingAsUser('guest');

        $response = $this->withHeaders(['Origin' => 'http://localhost:5173'])->withSession([])->deleteJson('/api/me');

        $response->assertNoContent();
        $this->assertDatabaseMissing((new User)->getTable(), ['id' => $guest->id]);
    }

    public function test_the_predefined_admin_account_cannot_delete_itself(): void
    {
        $protected = User::factory()->create(['level' => 'admin', 'is_active' => true, 'is_protected' => true]);
        $this->actingAs($protected);

        $response = $this->withHeaders(['Origin' => 'http://localhost:5173'])->withSession([])->deleteJson('/api/me');

        $response->assertStatus(422);
        $this->assertSame('protected_account', $response->json('error_code'));
        $this->assertDatabaseHas((new User)->getTable(), ['id' => $protected->id]);
    }

    public function test_a_user_who_still_owns_a_library_cannot_delete_their_account(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->withHeaders(['Origin' => 'http://localhost:5173'])->withSession([])->deleteJson('/api/me');

        $response->assertStatus(422);
        $this->assertSame('owns_libraries', $response->json('error_code'));
        $response->assertJsonPath('libraries.0.id', $library->id);
        $this->assertDatabaseHas((new User)->getTable(), ['id' => $owner->id]);
    }

    /** Ties both halves together: transfer ownership away, then the self-deletion that was blocked now succeeds. */
    public function test_self_deletion_succeeds_after_ownership_is_transferred_away(): void
    {
        $owner = $this->actingAsUser();
        $otherUser = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $this->putJson("/api/libraries/{$library->id}/owner", ['owner_id' => $otherUser->id])->assertOk();
        $response = $this->withHeaders(['Origin' => 'http://localhost:5173'])->withSession([])->deleteJson('/api/me');

        $response->assertNoContent();
        $this->assertDatabaseMissing((new User)->getTable(), ['id' => $owner->id]);
        $this->assertDatabaseHas((new Library)->getTable(), ['id' => $library->id, 'owner_id' => $otherUser->id]);
    }

    /** A library merely *shared* with the deleting user (not owned) is unaffected — only ownership blocks self-deletion. */
    public function test_a_library_merely_shared_with_the_user_does_not_block_self_deletion(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $library->shares()->create(['scope' => 'all_users']);
        $user = $this->actingAsUser();

        $response = $this->withHeaders(['Origin' => 'http://localhost:5173'])->withSession([])->deleteJson('/api/me');

        $response->assertNoContent();
        $this->assertDatabaseMissing((new User)->getTable(), ['id' => $user->id]);
    }

    public function test_only_the_requesting_users_own_account_can_be_deleted_this_way(): void
    {
        $this->actingAsUser();
        $someoneElse = User::factory()->create(['level' => 'user', 'is_active' => true]);

        $this->withHeaders(['Origin' => 'http://localhost:5173'])->withSession([])->deleteJson('/api/me');

        $this->assertDatabaseHas((new User)->getTable(), ['id' => $someoneElse->id]);
    }
}
