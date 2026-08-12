<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for a bug found while adding admin-route test coverage:
 * EnsureUserIsActive used to call auth()->guard('sanctum')->logout() when
 * rejecting a deactivated account — the 'sanctum' guard behind `auth:sanctum`
 * is a stateless RequestGuard with no logout() method, so every deactivated
 * account's next authenticated request 500ed instead of getting the intended
 * 403 "Account is deactivated." response.
 */
class EnsureUserIsActiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deactivated_user_gets_a_clean_403_instead_of_a_500(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->actingAs($user);

        $response = $this->getJson('/api/me');

        $response->assertStatus(403);
        $response->assertJson(['message' => 'Account is deactivated.']);
    }
}
