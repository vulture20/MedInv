<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PUT /libraries/{library} (LibraryController::update()) — editing a
 * library's name/description (briefing 5.), restricted to its owner or an
 * admin (LibraryAccessService::canWrite()). This endpoint already existed
 * but had no dedicated test coverage and no frontend UI at all until now.
 */
class LibraryUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $level): User
    {
        return User::factory()->create(['level' => $level, 'is_active' => true]);
    }

    private function library(User $owner, array $overrides = []): Library
    {
        return Library::query()->create([
            'name' => 'Novels',
            'description' => 'Original description',
            'media_type' => 'book',
            'owner_id' => $owner->id,
            ...$overrides,
        ]);
    }

    public function test_owner_can_update_name_and_description(): void
    {
        $owner = $this->user('user');
        $library = $this->library($owner);
        $this->actingAs($owner);

        $response = $this->putJson("/api/libraries/{$library->id}", [
            'name' => 'Science Fiction',
            'description' => 'Updated description',
        ]);

        $response->assertOk();
        $response->assertJsonPath('name', 'Science Fiction');
        $response->assertJsonPath('description', 'Updated description');
        $this->assertDatabaseHas((new Library)->getTable(), [
            'id' => $library->id,
            'name' => 'Science Fiction',
            'description' => 'Updated description',
        ]);
    }

    public function test_admin_can_update_any_library(): void
    {
        $owner = $this->user('user');
        $admin = $this->user('admin');
        $library = $this->library($owner);
        $this->actingAs($admin);

        $response = $this->putJson("/api/libraries/{$library->id}", ['name' => 'Renamed by admin']);

        $response->assertOk();
        $response->assertJsonPath('name', 'Renamed by admin');
    }

    public function test_a_non_owner_non_admin_user_cannot_update_the_library(): void
    {
        $owner = $this->user('user');
        $otherUser = $this->user('user');
        $library = $this->library($owner);
        $this->actingAs($otherUser);

        $response = $this->putJson("/api/libraries/{$library->id}", ['name' => 'Hijacked']);

        $response->assertStatus(403);
        $this->assertDatabaseHas((new Library)->getTable(), ['id' => $library->id, 'name' => 'Novels']);
    }

    public function test_a_guest_cannot_reach_the_update_endpoint_at_all(): void
    {
        $owner = $this->user('user');
        $guest = $this->user('guest');
        $library = $this->library($owner);
        $this->actingAs($guest);

        $response = $this->putJson("/api/libraries/{$library->id}", ['name' => 'Hijacked by guest']);

        $response->assertStatus(403);
    }

    public function test_description_can_be_cleared_to_null(): void
    {
        $owner = $this->user('user');
        $library = $this->library($owner);
        $this->actingAs($owner);

        $response = $this->putJson("/api/libraries/{$library->id}", ['description' => null]);

        $response->assertOk();
        $response->assertJsonPath('description', null);
    }

    public function test_name_alone_can_be_updated_without_touching_description(): void
    {
        $owner = $this->user('user');
        $library = $this->library($owner);
        $this->actingAs($owner);

        $response = $this->putJson("/api/libraries/{$library->id}", ['name' => 'Renamed only']);

        $response->assertOk();
        $response->assertJsonPath('name', 'Renamed only');
        $response->assertJsonPath('description', 'Original description');
    }

    /** media_type is immutable (briefing 5.) — update() doesn't even accept the field, so sending it is silently ignored rather than erroring. */
    public function test_media_type_cannot_be_changed_via_update(): void
    {
        $owner = $this->user('user');
        $library = $this->library($owner);
        $this->actingAs($owner);

        $response = $this->putJson("/api/libraries/{$library->id}", ['name' => 'Still a book library', 'media_type' => 'cd']);

        $response->assertOk();
        $this->assertDatabaseHas((new Library)->getTable(), ['id' => $library->id, 'media_type' => 'book']);
    }
}
