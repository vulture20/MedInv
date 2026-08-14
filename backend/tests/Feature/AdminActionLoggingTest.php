<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Admin management action audit trail: creating/updating/deactivating/
 * reactivating/deleting a user account, deleting a library, and
 * transferring a library's ownership were all previously unlogged on
 * success — only a handful of *rejection* paths (protected account, owns
 * libraries) went through Controller::logApiError(). See AuthLoggingTest's
 * docblock for why every test here also loosely allows Log::debug() (the
 * per-request "Frontend access" entry LogFrontendAccess always writes).
 */
class AdminActionLoggingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_creating_a_user_is_logged(): void
    {
        $admin = $this->actingAsAdmin();

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('User created', Mockery::on(function ($context) use ($admin) {
            return $context['actor_id'] === $admin->id
                && $context['email'] === 'newbie@example.com'
                && $context['level'] === 'user';
        }));

        $this->postJson('/api/admin/users', [
            'name' => 'Newbie', 'email' => 'newbie@example.com', 'password' => 'Sup3r$ecret!', 'level' => 'user',
        ])->assertCreated();
    }

    public function test_updating_a_users_level_is_logged_with_the_change(): void
    {
        $admin = $this->actingAsAdmin();
        $target = User::factory()->create(['level' => 'user', 'is_active' => true]);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('User updated', Mockery::on(function ($context) use ($admin, $target) {
            return $context['actor_id'] === $admin->id
                && $context['user_id'] === $target->id
                && $context['changes'] === ['level' => 'admin'];
        }));

        $this->putJson("/api/admin/users/{$target->id}", ['level' => 'admin'])->assertOk();
    }

    public function test_deactivating_a_user_is_logged(): void
    {
        $admin = $this->actingAsAdmin();
        $target = User::factory()->create(['level' => 'user', 'is_active' => true]);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('User deactivated', Mockery::on(function ($context) use ($admin, $target) {
            return $context['actor_id'] === $admin->id && $context['user_id'] === $target->id && $context['email'] === $target->email;
        }));

        $this->postJson("/api/admin/users/{$target->id}/deactivate")->assertOk();
    }

    public function test_reactivating_a_user_is_logged(): void
    {
        $admin = $this->actingAsAdmin();
        $target = User::factory()->create(['level' => 'user', 'is_active' => false]);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('User reactivated', Mockery::on(function ($context) use ($admin, $target) {
            return $context['actor_id'] === $admin->id && $context['user_id'] === $target->id;
        }));

        $this->postJson("/api/admin/users/{$target->id}/reactivate")->assertOk();
    }

    public function test_deleting_a_user_is_logged(): void
    {
        $admin = $this->actingAsAdmin();
        $target = User::factory()->create(['level' => 'user', 'is_active' => true]);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('User deleted', Mockery::on(function ($context) use ($admin, $target) {
            return $context['actor_id'] === $admin->id && $context['user_id'] === $target->id && $context['email'] === $target->email;
        }));

        $this->deleteJson("/api/admin/users/{$target->id}")->assertNoContent();
    }

    public function test_deleting_a_library_is_logged_with_its_item_count(): void
    {
        $admin = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Doomed', 'media_type' => 'book', 'owner_id' => $admin->id]);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Library deleted', Mockery::on(function ($context) use ($admin, $library) {
            return $context['actor_id'] === $admin->id
                && $context['library_id'] === $library->id
                && $context['name'] === 'Doomed'
                && $context['item_count'] === 0;
        }));

        $this->deleteJson("/api/libraries/{$library->id}")->assertNoContent();
    }

    public function test_transferring_library_ownership_is_logged(): void
    {
        $admin = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Handoff', 'media_type' => 'book', 'owner_id' => $admin->id]);
        $newOwner = User::factory()->create(['level' => 'user', 'is_active' => true]);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Library ownership transferred', Mockery::on(function ($context) use ($admin, $library, $newOwner) {
            return $context['actor_id'] === $admin->id
                && $context['library_id'] === $library->id
                && $context['previous_owner_id'] === $admin->id
                && $context['new_owner_id'] === $newOwner->id;
        }));

        $this->putJson("/api/libraries/{$library->id}/owner", ['owner_id' => $newOwner->id])->assertOk();
    }
}
