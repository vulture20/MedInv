<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GitHub issue #175: an admin setting another account's password directly
 * via UserController::update() — unlike the user's own self-service
 * change (AccountSettingsTest, GitHub issue #174), this needs no current-
 * password check at all, and the field is optional ('sometimes'), the
 * same "blank means unchanged" shape AdminSettingsController::updateMail()'s
 * SMTP password field already established.
 */
class AdminUserPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_an_admin_can_set_another_users_password(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create(['level' => 'user', 'is_active' => true]);

        $this->putJson("/api/admin/users/{$target->id}", ['password' => 'NewPassw0rd!'])->assertOk();

        $this->assertTrue(Hash::check('NewPassw0rd!', $target->fresh()->password));
    }

    /** The whole point of the field being 'sometimes': omitting it entirely must never touch the existing password. */
    public function test_omitting_the_password_field_leaves_the_password_unchanged(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $originalHash = $target->password;

        $this->putJson("/api/admin/users/{$target->id}", ['name' => 'Renamed'])->assertOk();

        $this->assertSame($originalHash, $target->fresh()->password);
    }

    public function test_setting_a_password_that_violates_the_policy_is_rejected(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $originalHash = $target->password;

        $this->putJson("/api/admin/users/{$target->id}", ['password' => 'short'])->assertStatus(422);

        $this->assertSame($originalHash, $target->fresh()->password);
    }

    /** No current-password prompt at all — an admin editing another account already has strictly broader unchecked power over it. */
    public function test_setting_a_password_requires_no_current_password(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create(['level' => 'user', 'is_active' => true]);

        $this->putJson("/api/admin/users/{$target->id}", ['password' => 'AnotherGood1!'])
            ->assertOk()
            ->assertJsonMissing(['current_password']);
    }

    /** The predefined admin account is exempt from every edit, password included. */
    public function test_the_protected_account_cannot_have_its_password_changed(): void
    {
        $this->actingAsAdmin();
        $protected = User::factory()->create(['level' => 'admin', 'is_active' => true, 'is_protected' => true]);
        $originalHash = $protected->password;

        $this->putJson("/api/admin/users/{$protected->id}", ['password' => 'NewPassw0rd!'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'protected_account');

        $this->assertSame($originalHash, $protected->fresh()->password);
    }

    /** A password set this way is never echoed back in the response — User::password stays #[Hidden] regardless of how it was written. */
    public function test_the_response_never_includes_the_password_hash(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create(['level' => 'user', 'is_active' => true]);

        $response = $this->putJson("/api/admin/users/{$target->id}", ['password' => 'NewPassw0rd!'])->assertOk();

        $this->assertArrayNotHasKey('password', $response->json());
    }
}
