<?php

namespace Tests\Feature;

use App\Models\MetadataPlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #221, a security-review finding: a `type: 'select'` config
 * field (e.g. the Amazon providers' `marketplace`, constrained to
 * `AmazonScraping::MARKETPLACES`) was previously only enforced by
 * PluginsPage.tsx's `<select>` widget — `PUT /admin/metadata/plugins/{id}`
 * itself accepted any string, trivially bypassed by calling the endpoint
 * directly. That mattered concretely for `marketplace`: it becomes the
 * host of the Amazon scraper's own outbound request
 * (`AmazonScraping::baseUrl()`), so an unvalidated value was a real SSRF
 * with host control. See MetadataController::assertValidPluginConfig()'s
 * own docblock for the fix.
 */
class MetadataPluginConfigValidationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_an_unrecognized_marketplace_value_is_rejected(): void
    {
        $this->actingAsAdmin();
        $plugin = MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.amazon', 'name' => 'Amazon', 'media_type' => 'dvd_bluray', 'enabled' => true,
        ]);

        $response = $this->putJson("/api/admin/metadata/plugins/{$plugin->id}", [
            'config' => ['marketplace' => 'attacker-controlled.example'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('config.marketplace');
        $this->assertNull($plugin->fresh()->config['marketplace'] ?? null);
    }

    public function test_a_declared_marketplace_option_is_accepted(): void
    {
        $this->actingAsAdmin();
        $plugin = MetadataPlugin::query()->create([
            'provider_key' => 'book.amazon', 'name' => 'Amazon', 'media_type' => 'book', 'enabled' => true,
        ]);

        $response = $this->putJson("/api/admin/metadata/plugins/{$plugin->id}", [
            'config' => ['marketplace' => 'amazon.de'],
        ]);

        $response->assertOk();
        $this->assertSame('amazon.de', $plugin->fresh()->config['marketplace']);
    }

    /** A provider with no `select`-type field at all (e.g. a plain password-type api_key) is unaffected — this validation only ever applies to a declared `select` field, exactly as before this fix for every other field type. */
    public function test_a_provider_with_no_select_field_is_unaffected(): void
    {
        $this->actingAsAdmin();
        $plugin = MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.upcmdb', 'name' => 'UPCitemdb', 'media_type' => 'dvd_bluray', 'enabled' => true,
        ]);

        $response = $this->putJson("/api/admin/metadata/plugins/{$plugin->id}", [
            'config' => ['api_key' => 'anything at all'],
        ]);

        $response->assertOk();
        $this->assertSame('anything at all', $plugin->fresh()->config['api_key']);
    }
}
