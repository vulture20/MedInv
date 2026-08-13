<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\LibraryShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #33: LibraryController::updateShares() (briefing 4.3) had
 * no test coverage at all. LibraryAccessServiceTest covers
 * canRead()/canWrite()/visibleLibrariesQuery() directly; this covers the
 * actual HTTP endpoint permission checks and replace-semantics.
 */
class LibrarySharesTest extends TestCase
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

    public function test_owner_can_set_shares(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);

        $response = $this->putJson("/api/libraries/{$library->id}/shares", [
            'shares' => [['scope' => 'guest'], ['scope' => 'all_users']],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas((new LibraryShare)->getTable(), ['library_id' => $library->id, 'scope' => 'guest']);
        $this->assertDatabaseHas((new LibraryShare)->getTable(), ['library_id' => $library->id, 'scope' => 'all_users']);
    }

    public function test_admin_can_set_shares_for_a_library_they_do_not_own(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = $this->library($owner->id);
        $this->actingAsUser('admin');

        $response = $this->putJson("/api/libraries/{$library->id}/shares", ['shares' => [['scope' => 'guest']]]);

        $response->assertOk();
        $this->assertDatabaseHas((new LibraryShare)->getTable(), ['library_id' => $library->id, 'scope' => 'guest']);
    }

    public function test_a_non_owner_non_admin_cannot_set_shares(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = $this->library($owner->id);
        $this->actingAsUser(); // a different, unrelated user

        $response = $this->putJson("/api/libraries/{$library->id}/shares", ['shares' => [['scope' => 'guest']]]);

        $response->assertForbidden();
        $this->assertDatabaseMissing((new LibraryShare)->getTable(), ['library_id' => $library->id]);
    }

    public function test_a_guest_cannot_set_shares(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = $this->library($owner->id);
        $this->actingAsUser('guest');

        $response = $this->putJson("/api/libraries/{$library->id}/shares", ['shares' => [['scope' => 'guest']]]);

        $response->assertForbidden();
    }

    public function test_updating_shares_replaces_the_full_list_rather_than_merging(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);
        LibraryShare::query()->create(['library_id' => $library->id, 'scope' => 'guest']);
        $oldTarget = User::factory()->create(['level' => 'user', 'is_active' => true]);
        LibraryShare::query()->create(['library_id' => $library->id, 'scope' => 'user', 'user_id' => $oldTarget->id]);

        $response = $this->putJson("/api/libraries/{$library->id}/shares", ['shares' => [['scope' => 'all_users']]]);

        $response->assertOk();
        $table = (new LibraryShare)->getTable();
        $this->assertSame(1, LibraryShare::query()->where('library_id', $library->id)->count());
        $this->assertDatabaseHas($table, ['library_id' => $library->id, 'scope' => 'all_users']);
        $this->assertDatabaseMissing($table, ['library_id' => $library->id, 'scope' => 'guest']);
        $this->assertDatabaseMissing($table, ['library_id' => $library->id, 'scope' => 'user', 'user_id' => $oldTarget->id]);
    }

    public function test_an_empty_shares_array_removes_every_existing_share(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);
        LibraryShare::query()->create(['library_id' => $library->id, 'scope' => 'guest']);

        $response = $this->putJson("/api/libraries/{$library->id}/shares", ['shares' => []]);

        $response->assertOk();
        $this->assertSame(0, LibraryShare::query()->where('library_id', $library->id)->count());
    }

    public function test_scope_user_requires_a_user_id(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);

        $response = $this->putJson("/api/libraries/{$library->id}/shares", ['shares' => [['scope' => 'user']]]);

        $response->assertStatus(422);
        $this->assertSame(0, LibraryShare::query()->where('library_id', $library->id)->count());
    }

    public function test_scope_user_with_a_nonexistent_user_id_is_rejected(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);

        $response = $this->putJson("/api/libraries/{$library->id}/shares", [
            'shares' => [['scope' => 'user', 'user_id' => 999999]],
        ]);

        $response->assertStatus(422);
    }

    public function test_an_invalid_scope_is_rejected(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);

        $response = $this->putJson("/api/libraries/{$library->id}/shares", ['shares' => [['scope' => 'everyone']]]);

        $response->assertStatus(422);
    }

    public function test_the_response_includes_the_shared_users_name_and_email(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);
        $target = User::factory()->create(['level' => 'user', 'name' => 'Bob', 'email' => 'bob@example.com', 'is_active' => true]);

        $response = $this->putJson("/api/libraries/{$library->id}/shares", [
            'shares' => [['scope' => 'user', 'user_id' => $target->id]],
        ]);

        $response->assertOk();
        $response->assertJsonPath('shares.0.user.name', 'Bob');
        $response->assertJsonPath('shares.0.user.email', 'bob@example.com');
    }
}
