<?php

namespace Tests\Feature;

use App\Models\MetadataPlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GitHub issue #160, following the user's own explicit request after
 * #157: POST /admin/metadata/plugins/{plugin}/test checks a candidate
 * config against the real provider API before an admin has necessarily
 * saved it — see TmdbProvider::testConfig()'s own docblock for the
 * TMDB-specific request shape, and MetadataController::plugins()'s
 * `supports_config_test` attribute (covered by
 * MetadataPluginEanSupportTest.php's sibling test style) for how the
 * frontend knows to show the "Test" button at all.
 */
class MetadataPluginConfigTestTest extends TestCase
{
    use RefreshDatabase;

    private const TMDB_BASE_URL = 'https://api.themoviedb.org/3';

    private function admin(): User
    {
        return User::factory()->create(['level' => 'admin', 'is_active' => true]);
    }

    private function tmdbPlugin(): MetadataPlugin
    {
        return MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.tmdb', 'name' => 'TMDB', 'media_type' => 'dvd_bluray', 'enabled' => false,
        ]);
    }

    public function test_plugins_endpoint_flags_tmdb_as_testable_and_upcmdb_as_not(): void
    {
        $admin = $this->admin();
        MetadataPlugin::query()->create(['provider_key' => 'dvd_bluray.tmdb', 'name' => 'TMDB', 'media_type' => 'dvd_bluray', 'enabled' => false]);
        MetadataPlugin::query()->create(['provider_key' => 'dvd_bluray.upcmdb', 'name' => 'UPCMDB', 'media_type' => 'dvd_bluray', 'enabled' => true]);

        $response = $this->actingAs($admin)->getJson('/api/admin/metadata/plugins');

        $response->assertOk();
        $tmdb = collect($response->json())->firstWhere('provider_key', 'dvd_bluray.tmdb');
        $upcmdb = collect($response->json())->firstWhere('provider_key', 'dvd_bluray.upcmdb');
        $this->assertTrue($tmdb['supports_config_test']);
        $this->assertFalse($upcmdb['supports_config_test']);
    }

    public function test_returns_valid_true_for_a_working_token(): void
    {
        $admin = $this->admin();
        $plugin = $this->tmdbPlugin();
        Http::fake([self::TMDB_BASE_URL.'/authentication*' => Http::response(['success' => true], 200)]);

        $response = $this->actingAs($admin)->postJson("/api/admin/metadata/plugins/{$plugin->id}/test", [
            'config' => ['read_access_token' => 'a-valid-token'],
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('valid'));
    }

    public function test_returns_valid_false_for_a_rejected_token(): void
    {
        $admin = $this->admin();
        $plugin = $this->tmdbPlugin();
        Http::fake([self::TMDB_BASE_URL.'/authentication*' => Http::response(['success' => false], 401)]);

        $response = $this->actingAs($admin)->postJson("/api/admin/metadata/plugins/{$plugin->id}/test", [
            'config' => ['read_access_token' => 'a-bogus-token'],
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('valid'));
    }

    /** The check itself failing (not the same as "confirmed invalid") is a 422 with a distinct error_code, the same distinction TmdbProviderTest.php's own unit-level test already covers for the provider itself. */
    public function test_a_failed_check_itself_returns_a_422_with_an_error_code(): void
    {
        $admin = $this->admin();
        $plugin = $this->tmdbPlugin();
        Http::fake([self::TMDB_BASE_URL.'/authentication*' => Http::response([], 503)]);

        $response = $this->actingAs($admin)->postJson("/api/admin/metadata/plugins/{$plugin->id}/test", [
            'config' => ['read_access_token' => 'some-token'],
        ]);

        $response->assertStatus(422);
        $this->assertSame('config_test_failed', $response->json('error_code'));
    }

    /** A provider that doesn't implement TestableMetadataProvider at all — the defensive backstop PluginsPage.tsx's own "Test" button visibility should already prevent reaching in normal use. */
    public function test_a_non_testable_provider_returns_a_422_with_an_error_code(): void
    {
        $admin = $this->admin();
        $plugin = MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.upcmdb', 'name' => 'UPCMDB', 'media_type' => 'dvd_bluray', 'enabled' => true,
        ]);

        $response = $this->actingAs($admin)->postJson("/api/admin/metadata/plugins/{$plugin->id}/test", [
            'config' => ['api_key' => 'whatever'],
        ]);

        $response->assertStatus(422);
        $this->assertSame('not_testable', $response->json('error_code'));
    }

    public function test_requires_admin(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $plugin = $this->tmdbPlugin();

        $response = $this->actingAs($user)->postJson("/api/admin/metadata/plugins/{$plugin->id}/test", [
            'config' => ['read_access_token' => 'whatever'],
        ]);

        $response->assertForbidden();
    }
}
