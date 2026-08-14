<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRegistry;
use App\Models\MetadataPlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub topic: drag-to-reorder metadata plugins in PluginsPage.tsx (per
 * media type — priority only ever ranks providers within one media type,
 * see the grouping change in the same commit). A drag list needs *some*
 * deterministic initial order to render, but
 * MetadataProviderRegistry::syncToDatabase() never sets an explicit
 * priority on first insert, so every provider starts tied at the column
 * default (0). Both GET /admin/metadata/plugins
 * (MetadataController::plugins()) and enabledProvidersFor() (the actual
 * import-resolution order) now break that tie with `orderBy('id')` — this
 * covers that the tie-break is deterministic, and that an explicit
 * priority still wins once an admin actually reorders something.
 */
class MetadataPluginOrderingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    private function plugin(string $key, string $name, int $priority = 0): MetadataPlugin
    {
        return MetadataPlugin::query()->create([
            'provider_key' => $key, 'name' => $name, 'media_type' => 'book', 'enabled' => true, 'priority' => $priority,
        ]);
    }

    public function test_plugins_endpoint_breaks_a_priority_tie_by_insertion_order(): void
    {
        $this->actingAsAdmin();
        $second = $this->plugin('book.second', 'Second');
        $first = $this->plugin('book.first', 'First');
        // Both default to priority=0 — a full tie. Insertion order above is
        // deliberately "second, then first" so this only passes if the
        // tie-break is really id-based, not accidentally name/key-alphabetical.

        $response = $this->getJson('/api/admin/metadata/plugins');

        $response->assertOk();
        $keys = collect($response->json())->pluck('provider_key')->all();
        $this->assertSame([$second->provider_key, $first->provider_key], $keys);
    }

    public function test_an_explicit_priority_wins_over_insertion_order(): void
    {
        $this->actingAsAdmin();
        $second = $this->plugin('book.second', 'Second');
        $first = $this->plugin('book.first', 'First');
        $first->update(['priority' => 0]);
        $second->update(['priority' => 1]);

        $response = $this->getJson('/api/admin/metadata/plugins');

        $keys = collect($response->json())->pluck('provider_key')->all();
        $this->assertSame([$first->provider_key, $second->provider_key], $keys);
    }

    /**
     * enabledProvidersFor() only ever returns providers actually listed in
     * MetadataProviderRegistry::defaultProviders() — using two of the real
     * book providers here rather than made-up provider_keys, since a
     * provider_key with no matching registered class is filtered out
     * entirely (see that method's ->filter() call).
     */
    public function test_enabled_providers_for_breaks_a_priority_tie_by_insertion_order(): void
    {
        MetadataPlugin::query()->where('media_type', 'book')->delete();
        $second = $this->plugin('book.google_books', 'Google Books');
        $first = $this->plugin('book.open_library', 'OpenLibrary');

        $ordered = app(MetadataProviderRegistry::class)->enabledProvidersFor('book');

        $this->assertSame([$second->provider_key, $first->provider_key], $ordered->map->key()->all());
    }
}
