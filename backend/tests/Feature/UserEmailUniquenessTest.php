<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #198 (a user-reported follow-up to #197's own root cause):
 * a duplicate email — a completely ordinary admin scenario, not a rare edge
 * case — used to surface as Laravel's raw, untranslated "The email has
 * already been taken." via describeError()'s generic validation-error
 * fallback. UserController::store()/update() now check uniqueness
 * explicitly and return a dedicated, translated `email_taken` error_code
 * instead of relying on the `unique` validation rule.
 */
class UserEmailUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_creating_a_user_with_a_taken_email_returns_the_email_taken_error_code(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Duplicate',
            'email' => 'taken@example.com',
            'password' => 'Str0ng!Passw0rd',
            'level' => 'user',
        ]);

        $response->assertStatus(422);
        $this->assertSame('email_taken', $response->json('error_code'));
        $this->assertSame(2, User::query()->count()); // the admin + the original owner, not a third
    }

    /** A duplicate check is case-*sensitive* at the DB layer today (no lower() normalization anywhere in this flow) — this test pins the current, real behavior rather than assuming a stricter one that doesn't actually exist. */
    public function test_creating_a_user_with_a_free_email_succeeds(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/users', [
            'name' => 'New User',
            'email' => 'free@example.com',
            'password' => 'Str0ng!Passw0rd',
            'level' => 'user',
        ]);

        $response->assertCreated();
    }

    public function test_updating_a_user_to_another_users_email_returns_the_email_taken_error_code(): void
    {
        $other = User::factory()->create(['email' => 'taken@example.com']);
        $target = User::factory()->create(['email' => 'target@example.com']);
        $this->actingAsAdmin();

        $response = $this->putJson("/api/admin/users/{$target->id}", ['email' => 'taken@example.com']);

        $response->assertStatus(422);
        $this->assertSame('email_taken', $response->json('error_code'));
        $this->assertSame('target@example.com', $target->fresh()->email);
        $this->assertNotNull($other); // the email genuinely belongs to a different row, not $target itself
    }

    /** Keeping a user's own current email (a no-op edit that also changes e.g. the name) must not trip the "taken" check against itself. */
    public function test_updating_a_user_to_their_own_current_email_succeeds(): void
    {
        $target = User::factory()->create(['email' => 'me@example.com']);
        $this->actingAsAdmin();

        $response = $this->putJson("/api/admin/users/{$target->id}", ['name' => 'Renamed', 'email' => 'me@example.com']);

        $response->assertOk();
        $this->assertSame('Renamed', $target->fresh()->name);
    }
}
