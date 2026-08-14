<?php

namespace Tests\Feature;

use App\Domain\Mail\MailStatusService;
use App\Models\LanguagePack;
use App\Models\Library;
use App\Models\MetadataPlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Mockery;
use Tests\TestCase;

/**
 * Four more previously-unlogged success paths: self-service password reset,
 * metadata plugin config changes (with secret-field redaction), language
 * pack management, and library share changes. See AuthLoggingTest's
 * docblock for why every test here also loosely allows Log::debug()
 * (LogFrontendAccess's per-request entry).
 */
class MoreAdminActionLoggingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    private function mailHealthy(): void
    {
        $this->partialMock(MailStatusService::class, function ($mock) {
            $mock->shouldReceive('isHealthy')->andReturn(true);
        });
    }

    public function test_a_completed_password_reset_is_logged(): void
    {
        $this->mailHealthy();
        $user = User::factory()->create();
        $token = Password::createToken($user);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Password reset completed', Mockery::on(function ($context) use ($user) {
            return $context['user_id'] === $user->id && $context['email'] === $user->email;
        }));

        $this->postJson('/api/password/reset', [
            'token' => $token, 'email' => $user->email, 'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertOk();
    }

    public function test_a_failed_password_reset_is_not_logged_as_completed(): void
    {
        $this->mailHealthy();
        $user = User::factory()->create();

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('log')->zeroOrMoreTimes();
        Log::shouldReceive('info')->never()->with('Password reset completed', Mockery::any());

        $this->postJson('/api/password/reset', [
            'token' => 'not-a-real-token', 'email' => $user->email, 'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertStatus(422);
    }

    public function test_updating_a_metadata_plugin_is_logged_with_its_api_key_redacted(): void
    {
        $admin = $this->actingAsAdmin();
        $plugin = MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.upcmdb', 'name' => 'UPCitemdb', 'media_type' => 'dvd_bluray', 'enabled' => true, 'priority' => 1,
        ]);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Metadata plugin updated', Mockery::on(function ($context) use ($admin, $plugin) {
            return $context['actor_id'] === $admin->id
                && $context['provider_key'] === $plugin->provider_key
                && $context['changes']['config']['api_key'] === '[REDACTED]';
        }));

        $this->putJson("/api/admin/metadata/plugins/{$plugin->id}", [
            'config' => ['api_key' => 'TOTALLY-SECRET-UPC-KEY'],
        ])->assertOk();
    }

    public function test_updating_a_metadata_plugin_without_a_password_type_field_is_not_redacted(): void
    {
        $admin = $this->actingAsAdmin();
        $plugin = MetadataPlugin::query()->create([
            'provider_key' => 'book.open_library', 'name' => 'Open Library', 'media_type' => 'book', 'enabled' => true, 'priority' => 3,
        ]);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Metadata plugin updated', Mockery::on(function ($context) use ($admin) {
            return $context['actor_id'] === $admin->id && $context['changes']['priority'] === 5;
        }));

        $this->putJson("/api/admin/metadata/plugins/{$plugin->id}", ['priority' => 5])->assertOk();
    }

    public function test_creating_a_language_pack_is_logged_without_the_full_translations_blob(): void
    {
        $admin = $this->actingAsAdmin();

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Language pack created', Mockery::on(function ($context) use ($admin) {
            return $context['actor_id'] === $admin->id && $context['code'] === 'fr' && ! array_key_exists('translations', $context);
        }));

        $this->postJson('/api/admin/languages', [
            'code' => 'fr', 'name' => 'Français', 'translations' => ['common' => ['name' => 'Nom']],
        ])->assertCreated();
    }

    public function test_updating_a_language_pack_is_logged(): void
    {
        $admin = $this->actingAsAdmin();
        $pack = LanguagePack::query()->create(['code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b']]);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Language pack updated', Mockery::on(function ($context) use ($admin) {
            return $context['actor_id'] === $admin->id && $context['code'] === 'fr' && $context['translations_changed'] === true;
        }));

        $this->putJson('/api/admin/languages/fr', ['translations' => ['a' => 'c']])->assertOk();
    }

    public function test_deleting_a_language_pack_is_logged(): void
    {
        $admin = $this->actingAsAdmin();
        LanguagePack::query()->create(['code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b']]);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Language pack deleted', Mockery::on(function ($context) use ($admin) {
            return $context['actor_id'] === $admin->id && $context['code'] === 'fr';
        }));

        $this->deleteJson('/api/admin/languages/fr')->assertNoContent();
    }

    public function test_updating_library_shares_is_logged(): void
    {
        $admin = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Shared', 'media_type' => 'book', 'owner_id' => $admin->id]);
        $target = User::factory()->create(['level' => 'user', 'is_active' => true]);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('Library shares updated', Mockery::on(function ($context) use ($admin, $library, $target) {
            return $context['actor_id'] === $admin->id
                && $context['library_id'] === $library->id
                && $context['guest'] === true
                && $context['all_users'] === false
                && $context['user_ids'] === [$target->id];
        }));

        $this->putJson("/api/libraries/{$library->id}/shares", [
            'shares' => [['scope' => 'guest'], ['scope' => 'user', 'user_id' => $target->id]],
        ])->assertOk();
    }
}
