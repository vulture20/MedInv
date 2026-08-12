<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaDvdBluray;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers two bugs behind "search returns absolutely nothing": (1) the
 * `fuzzy` query param failed Laravel's strict `boolean` validation rule for
 * the exact string ("false"/"true") axios sends a JS boolean as in a GET
 * request, 422ing on literally every search — silently, since
 * SearchPage.tsx never checked for a failed request; and (2)
 * MediaDvdBluray's `cast` column is a reserved SQL keyword, breaking the
 * fuzzy-search raw SQL for that media type specifically.
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): User
    {
        $user = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function createDvdBlurayLibrary(User $owner): Library
    {
        $library = Library::query()->create([
            'name' => 'Films',
            'media_type' => 'dvd_bluray',
            'owner_id' => $owner->id,
        ]);

        MediaDvdBluray::query()->create([
            'library_id' => $library->id,
            'title' => 'Frankenstein',
            'cast' => 'Boris Karloff',
            'director' => 'James Whale',
            'ean' => '1234567890123',
        ]);

        return $library;
    }

    public function test_search_with_fuzzy_false_as_sent_by_the_frontend_does_not_422(): void
    {
        $user = $this->actingAsUser();
        $this->createDvdBlurayLibrary($user);

        $response = $this->getJson('/api/search?query=Frankenstein&fuzzy=false');

        $response->assertOk();
        $this->assertCount(1, $response->json());
    }

    public function test_search_with_fuzzy_true_as_sent_by_the_frontend_does_not_422(): void
    {
        $user = $this->actingAsUser();
        $this->createDvdBlurayLibrary($user);

        $response = $this->getJson('/api/search?query=frankenstein&fuzzy=true');

        $response->assertOk();
        $this->assertCount(1, $response->json());
    }

    public function test_fuzzy_search_over_the_reserved_keyword_cast_column_does_not_500(): void
    {
        $user = $this->actingAsUser();
        $this->createDvdBlurayLibrary($user);

        // Matches only via the `cast` column, forcing SearchService to actually
        // build the `LOWER(cast) LIKE ?` fragment rather than short-circuiting
        // on an earlier column.
        $response = $this->getJson('/api/search?query=karloff&fuzzy=true');

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame('Frankenstein', $response->json('0.title'));
    }

    public function test_non_fuzzy_search_still_works(): void
    {
        $user = $this->actingAsUser();
        $this->createDvdBlurayLibrary($user);

        $response = $this->getJson('/api/search?query=Karloff');

        $response->assertOk();
        $this->assertCount(1, $response->json());
    }
}
