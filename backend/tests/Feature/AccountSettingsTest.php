<?php

namespace Tests\Feature;

use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Covers the logged-in user's own preferences (briefing 4.1,
 * AccountSettingsController::update()) — previously untested at all. In
 * particular GitHub issue #11: preferred_template used to be validated
 * against a hardcoded Rule::in(['light', 'dark']) with a TODO noting it
 * should check the registered set once installable templates existed
 * (GitHub issue #11's own prerequisite); now that Template exists
 * (briefing 10./11.4), it does.
 */
class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): User
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_a_user_can_set_preferred_template_to_light_or_dark(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/me/settings', ['preferred_template' => 'dark'])
            ->assertOk()
            ->assertJsonPath('preferred_template', 'dark');
    }

    public function test_setting_an_unregistered_template_is_rejected(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/me/settings', ['preferred_template' => 'nonexistent'])
            ->assertStatus(422);
    }

    /** GitHub issue #11's actual point: this used to 422 no matter what, since only 'light'/'dark' were ever accepted. */
    public function test_a_user_can_set_preferred_template_to_an_installed_template(): void
    {
        $user = $this->actingAsUser();
        Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'css' => ':root { --color-bg: #fdf6e3; }']);

        $this->putJson('/api/me/settings', ['preferred_template' => 'solarized'])
            ->assertOk()
            ->assertJsonPath('preferred_template', 'solarized');
        $this->assertSame('solarized', $user->fresh()->preferred_template);
    }

    public function test_setting_the_template_to_a_since_deleted_template_is_rejected(): void
    {
        $this->actingAsUser();
        $template = Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'css' => ':root { --color-bg: #fdf6e3; }']);
        $template->delete();

        $this->putJson('/api/me/settings', ['preferred_template' => 'solarized'])
            ->assertStatus(422);
    }

    public function test_a_user_can_update_preferred_language(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/me/settings', ['preferred_language' => 'fr'])
            ->assertOk()
            ->assertJsonPath('preferred_language', 'fr');
    }

    public function test_an_unauthenticated_request_cannot_update_settings(): void
    {
        $this->putJson('/api/me/settings', ['preferred_template' => 'dark'])->assertUnauthorized();
    }

    /**
     * GitHub issue #174: self-service password change. UserFactory's
     * default password is 'password' (see its own comment).
     */
    public function test_a_user_can_change_their_own_password(): void
    {
        $user = $this->actingAsUser();

        $this->putJson('/api/me/password', [
            'current_password' => 'password',
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ])->assertNoContent();

        $this->assertTrue(Hash::check('NewPassw0rd!', $user->fresh()->password));
    }

    /** The whole point of requiring current_password at all — otherwise anyone at an unlocked, still-logged-in session could lock the real owner out. */
    public function test_changing_the_password_with_the_wrong_current_password_is_rejected(): void
    {
        $user = $this->actingAsUser();

        $this->putJson('/api/me/password', [
            'current_password' => 'not-the-real-password',
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'invalid_current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    /** GitHub issue #174, briefing 12.1 — the same MedInvPasswordPolicy every other password field in this app already enforces. */
    public function test_changing_the_password_enforces_the_password_policy(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/me/password', [
            'current_password' => 'password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_changing_the_password_requires_the_confirmation_to_match(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/me/password', [
            'current_password' => 'password',
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'SomethingElse1!',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_an_unauthenticated_request_cannot_change_the_password(): void
    {
        $this->putJson('/api/me/password', [
            'current_password' => 'password',
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ])->assertUnauthorized();
    }
}
