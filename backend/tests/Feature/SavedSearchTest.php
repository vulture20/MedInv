<?php

namespace Tests\Feature;

use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #73's "nice to have": named, reusable search-mask filter
 * combinations. Purely personal (SavedSearchController checks ownership
 * itself, no LibraryAccessService involved — a saved search isn't
 * library-scoped data).
 */
class SavedSearchTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): User
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_a_user_can_save_and_then_list_their_own_search(): void
    {
        $this->actingAsUser();

        $store = $this->postJson('/api/saved-searches', [
            'name' => 'Cheap sci-fi books',
            'filters' => ['media_types' => ['book'], 'genre' => ['Sci-Fi'], 'price_max' => '10'],
        ]);
        $store->assertCreated();

        $index = $this->getJson('/api/saved-searches');
        $index->assertOk();
        $this->assertCount(1, $index->json());
        $this->assertSame('Cheap sci-fi books', $index->json('0.name'));
        $this->assertSame(['book'], $index->json('0.filters.media_types'));
    }

    public function test_a_user_only_sees_their_own_saved_searches(): void
    {
        $other = User::factory()->create(['level' => 'user', 'is_active' => true]);
        SavedSearch::query()->create(['user_id' => $other->id, 'name' => "Someone else's", 'filters' => []]);
        $this->actingAsUser();

        $response = $this->getJson('/api/saved-searches');

        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    public function test_a_user_can_delete_their_own_saved_search(): void
    {
        $user = $this->actingAsUser();
        $saved = SavedSearch::query()->create(['user_id' => $user->id, 'name' => 'Mine', 'filters' => []]);

        $response = $this->deleteJson("/api/saved-searches/{$saved->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing((new SavedSearch)->getTable(), ['id' => $saved->id]);
    }

    public function test_a_user_cannot_delete_another_users_saved_search(): void
    {
        $other = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $saved = SavedSearch::query()->create(['user_id' => $other->id, 'name' => "Someone else's", 'filters' => []]);
        $this->actingAsUser();

        $response = $this->deleteJson("/api/saved-searches/{$saved->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas((new SavedSearch)->getTable(), ['id' => $saved->id]);
    }

    /** Even an admin only manages their own bookmarks here — this isn't an admin-manageable resource like users/libraries. */
    public function test_an_admin_cannot_delete_another_users_saved_search_either(): void
    {
        $other = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $saved = SavedSearch::query()->create(['user_id' => $other->id, 'name' => "Someone else's", 'filters' => []]);
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        $response = $this->deleteJson("/api/saved-searches/{$saved->id}");

        $response->assertForbidden();
    }
}
