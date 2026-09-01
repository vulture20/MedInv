<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * UserController::destroy() (GitHub issue #34): deleting a user who still
 * owns libraries used to silently cascade-delete them — and everything
 * shared with other users/guests along with them (briefing 4.3) — with no
 * warning at all. Now rejected with a 422 instead; the admin must transfer
 * ownership first (see LibraryOwnershipTransferTest) or delete those
 * libraries deliberately.
 */
class UserDeletionOwnedLibrariesTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_deleting_a_user_without_any_libraries_still_succeeds(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);

        $response = $this->deleteJson("/api/admin/users/{$user->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing((new User)->getTable(), ['id' => $user->id]);
    }

    /**
     * GitHub issue #222, a privacy-review finding: unlike every other
     * user-referencing table, `sessions.user_id` has no FK constraint at
     * all, so an admin deleting a user who's still logged in elsewhere
     * (a second device/browser — this test's own row, not the admin's
     * current session) used to leave that session row, with its
     * ip_address/user_agent, orphaned until Laravel's own probabilistic
     * garbage collection happened to sweep it.
     */
    public function test_deleting_a_user_purges_their_other_sessions(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        DB::table('sessions')->insert([
            'id' => 'other-device-session',
            'user_id' => $user->id,
            'ip_address' => '203.0.113.5',
            'user_agent' => 'Mozilla/5.0',
            'payload' => base64_encode('irrelevant'),
            'last_activity' => time(),
        ]);

        $response = $this->deleteJson("/api/admin/users/{$user->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session']);
    }

    public function test_deleting_a_user_who_owns_a_library_is_rejected(): void
    {
        $this->actingAsAdmin();
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->deleteJson("/api/admin/users/{$owner->id}");

        $response->assertStatus(422);
        $this->assertSame('owns_libraries', $response->json('error_code'));
    }

    public function test_the_rejection_response_lists_the_owned_libraries(): void
    {
        $this->actingAsAdmin();
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->deleteJson("/api/admin/users/{$owner->id}");

        $response->assertJsonPath('libraries.0.id', $library->id);
        $response->assertJsonPath('libraries.0.name', 'Novels');
    }

    public function test_the_user_and_library_both_still_exist_after_a_rejected_deletion(): void
    {
        $this->actingAsAdmin();
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $this->deleteJson("/api/admin/users/{$owner->id}");

        $this->assertDatabaseHas((new User)->getTable(), ['id' => $owner->id]);
        $this->assertDatabaseHas((new Library)->getTable(), ['id' => $library->id]);
    }

    /** Ties both halves of the fix together: transfer ownership, then the deletion that was blocked now succeeds. */
    public function test_deletion_succeeds_after_ownership_is_transferred_away(): void
    {
        $admin = $this->actingAsAdmin();
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $this->putJson("/api/libraries/{$library->id}/owner", ['owner_id' => $admin->id])->assertOk();
        $response = $this->deleteJson("/api/admin/users/{$owner->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing((new User)->getTable(), ['id' => $owner->id]);
        $this->assertDatabaseHas((new Library)->getTable(), ['id' => $library->id, 'owner_id' => $admin->id]);
    }

    /** The predefined admin account is rejected for its own protected-account reason first, regardless of whether it owns libraries. */
    public function test_the_protected_admin_account_check_still_takes_priority(): void
    {
        $this->actingAsAdmin();
        $protected = User::factory()->create(['level' => 'admin', 'is_active' => true, 'is_protected' => true]);
        Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $protected->id]);

        $response = $this->deleteJson("/api/admin/users/{$protected->id}");

        $response->assertStatus(422);
        $this->assertSame('protected_account', $response->json('error_code'));
    }

    /**
     * Defense in depth (see the migration's docblock): even bypassing
     * UserController::destroy() entirely, the database itself now rejects
     * deleting a user who still owns libraries — libraries.owner_id is
     * restrictOnDelete(), no longer cascadeOnDelete().
     */
    public function test_the_database_itself_rejects_deleting_a_user_who_owns_a_library(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $this->expectException(QueryException::class);

        $owner->delete();
    }
}
