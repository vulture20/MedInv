<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\MetadataPlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GitHub issue #155's follow-up (explicit user request): an item captured
 * without a real EAN (issue #151) stores a generated `NoEAN-...`
 * placeholder — MediaItemDetailDialog's "refresh metadata" action queries
 * every enabled provider by the item's stored EAN, which for a placeholder
 * can only ever come back no_match, wasting a real request (and, for the
 * LLM providers, real cost) per provider for nothing. The frontend now
 * disables that button for such an item; this covers the server-side
 * backstop in MetadataController::refresh() that applies regardless of
 * which client called it. `Http::preventStrayRequests()` (Tests\TestCase)
 * is what actually proves the short-circuit fires here — an unfaked
 * provider call would throw rather than silently succeed or no-op.
 */
class NoEanMetadataRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): User
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_refreshing_metadata_for_a_no_ean_item_short_circuits_without_a_provider_lookup(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create([
            'library_id' => $library->id, 'title' => 'A Book', 'ean' => 'NoEAN-1234567890123',
        ]);
        MetadataPlugin::query()->create(['provider_key' => 'book.open_library', 'name' => 'Open Library', 'media_type' => 'book', 'enabled' => true]);

        // No Http::fake() at all — Http::preventStrayRequests() (base
        // TestCase) means the request would fail loudly with a "stray
        // request" exception if the short-circuit didn't fire before
        // OpenLibraryProvider got a chance to call out.
        $response = $this->getJson("/api/libraries/{$library->id}/items/{$item->id}/metadata/refresh");

        $response->assertOk();
        $response->assertJson(['status' => 'no_match', 'candidates' => []]);
    }

    /** Same setup, a real EAN — proves the short-circuit is specific to the placeholder, not a blanket change to refresh()'s behavior. */
    public function test_refreshing_metadata_for_an_item_with_a_real_ean_still_queries_providers(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        MetadataPlugin::query()->create(['provider_key' => 'book.open_library', 'name' => 'Open Library', 'media_type' => 'book', 'enabled' => true]);

        Http::fake([
            'https://openlibrary.org/api/books*' => Http::response([
                'ISBN:9780000000001' => ['title' => 'Dune (revised)', 'authors' => [['name' => 'Frank Herbert']]],
            ], 200),
            'https://openlibrary.org/isbn/*.json' => Http::response([], 200),
        ]);

        $response = $this->getJson("/api/libraries/{$library->id}/items/{$item->id}/metadata/refresh");

        $response->assertOk()->assertJson(['status' => 'candidates']);
        $this->assertSame('Dune (revised)', $response->json('merged.fields.title.value'));
    }
}
