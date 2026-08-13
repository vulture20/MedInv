<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/users (UserController::shareable()), GitHub issue #32: the
 * minimal user list the library-sharing UI's "share with a specific user"
 * picker uses. Unlike the admin-only /admin/users listing, this is
 * available to any non-guest user, since briefing 4.3 lets a library's
 * owner (not just an admin) manage its own shares.
 */
class ShareableUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_can_list_shareable_users(): void
    {
        $self = User::factory()->create(['level' => 'user', 'is_active' => true]);
        User::factory()->create(['level' => 'user', 'name' => 'Bob', 'is_active' => true]);
        $this->actingAs($self);

        $response = $this->getJson('/api/users');

        $response->assertOk();
        $this->assertTrue(collect($response->json())->contains('name', 'Bob'));
    }

    public function test_excludes_guest_level_users(): void
    {
        $self = User::factory()->create(['level' => 'user', 'is_active' => true]);
        User::factory()->create(['level' => 'guest', 'name' => 'Guesty', 'is_active' => true]);
        $this->actingAs($self);

        $response = $this->getJson('/api/users');

        $response->assertOk();
        $this->assertFalse(collect($response->json())->contains('name', 'Guesty'));
    }

    public function test_excludes_the_requesting_user_themselves(): void
    {
        $self = User::factory()->create(['level' => 'user', 'name' => 'Self', 'is_active' => true]);
        $this->actingAs($self);

        $response = $this->getJson('/api/users');

        $response->assertOk();
        $this->assertFalse(collect($response->json())->contains('id', $self->id));
    }

    public function test_only_exposes_id_and_name(): void
    {
        $self = User::factory()->create(['level' => 'user', 'is_active' => true]);
        User::factory()->create(['level' => 'user', 'name' => 'Bob', 'email' => 'bob@example.com', 'is_active' => true]);
        $this->actingAs($self);

        $response = $this->getJson('/api/users');

        $response->assertOk();
        $bob = collect($response->json())->firstWhere('name', 'Bob');
        $this->assertSame(['id', 'name'], array_keys($bob));
    }

    public function test_guests_cannot_list_shareable_users(): void
    {
        $guest = User::factory()->create(['level' => 'guest', 'is_active' => true]);
        $this->actingAs($guest);

        $response = $this->getJson('/api/users');

        $response->assertStatus(403);
    }

    public function test_an_admin_can_also_list_shareable_users(): void
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        User::factory()->create(['level' => 'user', 'name' => 'Bob', 'is_active' => true]);
        $this->actingAs($admin);

        $response = $this->getJson('/api/users');

        $response->assertOk();
        $this->assertTrue(collect($response->json())->contains('name', 'Bob'));
    }
}
