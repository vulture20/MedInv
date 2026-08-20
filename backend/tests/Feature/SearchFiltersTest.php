<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\MediaDvdBluray;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GitHub issue #73 — the search mask's structured filters (media type/
 * library scoping, field-specific text search, attribute filters, range
 * filters), layered on top of the free-text/fuzzy search SearchTest.php/
 * FuzzySearchTest.php already cover. `query` is deliberately omitted from
 * most requests here: GitHub issue #73 made it optional specifically so a
 * request can consist entirely of filters (browsing, not searching).
 */
class SearchFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'admin'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function seedOneOfEach(User $owner): void
    {
        $bookLibrary = Library::query()->create(['name' => 'Books', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create([
            'library_id' => $bookLibrary->id, 'title' => 'Dune', 'ean' => '9780000000001',
            'genre' => 'Sci-Fi', 'format' => 'Hardcover', 'language' => 'en', 'page_count' => 412,
            'price' => 15, 'release_date' => '1965-08-01',
        ]);

        $cdLibrary = Library::query()->create(['name' => 'CDs', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        MediaCd::query()->create([
            'library_id' => $cdLibrary->id, 'title' => 'OK Computer', 'ean' => '9780000000002',
            'medium' => 'CD', 'disc_count' => 1, 'runtime_seconds' => 3060,
            'price' => 9, 'release_date' => '1997-05-21',
        ]);

        $dvdLibrary = Library::query()->create(['name' => 'Films', 'media_type' => 'dvd_bluray', 'owner_id' => $owner->id]);
        MediaDvdBluray::query()->create([
            'library_id' => $dvdLibrary->id, 'title' => 'Metropolis', 'ean' => '9780000000003',
            'medium' => 'Blu-ray', 'disc_count' => 2, 'runtime_minutes' => 153, 'languages' => 'German, English',
            'price' => 20, 'production_year' => 1927,
        ]);
    }

    private function titles(TestResponse $response): array
    {
        return collect($response->json())->pluck('title')->all();
    }

    public function test_no_filters_at_all_returns_every_visible_item(): void
    {
        $owner = $this->actingAsUser();
        $this->seedOneOfEach($owner);

        $response = $this->getJson('/api/search');

        $response->assertOk();
        $this->assertCount(3, $response->json());
    }

    public function test_media_types_filter_narrows_to_the_requested_types(): void
    {
        $owner = $this->actingAsUser();
        $this->seedOneOfEach($owner);

        $response = $this->getJson('/api/search?media_types[]=book&media_types[]=cd');

        $response->assertOk();
        $this->assertEqualsCanonicalizing(['Dune', 'OK Computer'], $this->titles($response));
    }

    public function test_library_ids_filter_narrows_to_the_requested_library(): void
    {
        $owner = $this->actingAsUser();
        $this->seedOneOfEach($owner);
        $bookLibrary = Library::query()->where('media_type', 'book')->firstOrFail();

        $response = $this->getJson("/api/search?library_ids[]={$bookLibrary->id}");

        $response->assertOk();
        $this->assertSame(['Dune'], $this->titles($response));
    }

    /** A library id the user can't actually see must not leak its items in just because it was requested. */
    public function test_library_ids_filter_cannot_bypass_visibility_scoping(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Secret', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Hidden', 'ean' => '9780000000009']);
        $this->actingAsUser('user');

        $response = $this->getJson("/api/search?library_ids[]={$library->id}");

        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    public function test_field_title_does_not_match_a_query_that_only_appears_in_the_description(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Books', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'description' => 'A desert planet epic.', 'ean' => '9780000000001']);

        $response = $this->getJson('/api/search?query=desert&field=title');

        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    public function test_field_creator_matches_the_media_types_own_creator_column(): void
    {
        $owner = $this->actingAsUser();
        $this->seedOneOfEach($owner);
        MediaBook::query()->where('title', 'Dune')->update(['authors' => 'Frank Herbert']);
        MediaDvdBluray::query()->where('title', 'Metropolis')->update(['director' => 'Fritz Lang']);

        $response = $this->getJson('/api/search?query=Lang&field=creator');

        $response->assertOk();
        $this->assertSame(['Metropolis'], $this->titles($response));
    }

    public function test_genre_filter_matches_only_books_with_that_genre_and_excludes_other_media_types(): void
    {
        $owner = $this->actingAsUser();
        $this->seedOneOfEach($owner);

        $response = $this->getJson('/api/search?genre[]=Sci-Fi');

        $response->assertOk();
        $this->assertSame(['Dune'], $this->titles($response));
    }

    public function test_genre_filter_with_no_matching_book_returns_nothing(): void
    {
        $owner = $this->actingAsUser();
        $this->seedOneOfEach($owner);

        $response = $this->getJson('/api/search?genre[]=Romance');

        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    public function test_medium_filter_matches_cd_and_dvd_but_excludes_books(): void
    {
        $owner = $this->actingAsUser();
        $this->seedOneOfEach($owner);

        $response = $this->getJson('/api/search?medium[]=CD&medium[]=Blu-ray');

        $response->assertOk();
        $this->assertEqualsCanonicalizing(['OK Computer', 'Metropolis'], $this->titles($response));
    }

    public function test_languages_filter_matches_a_substring_of_the_comma_separated_column(): void
    {
        $owner = $this->actingAsUser();
        $this->seedOneOfEach($owner);

        $response = $this->getJson('/api/search?languages[]=English');

        $response->assertOk();
        $this->assertSame(['Metropolis'], $this->titles($response));
    }

    public function test_price_range_filter(): void
    {
        $owner = $this->actingAsUser();
        $this->seedOneOfEach($owner);

        $response = $this->getJson('/api/search?price_min=10&price_max=18');

        $response->assertOk();
        $this->assertSame(['Dune'], $this->titles($response));
    }

    public function test_year_range_filter_uses_release_date_for_book_and_cd(): void
    {
        $owner = $this->actingAsUser();
        $this->seedOneOfEach($owner);

        $response = $this->getJson('/api/search?year_min=1990&year_max=2000');

        $response->assertOk();
        $this->assertSame(['OK Computer'], $this->titles($response));
    }

    /** DVD-Blu-ray uses its own `production_year` column, not release_date, per StatisticsService's own precedent. */
    public function test_year_range_filter_uses_production_year_for_dvd_bluray(): void
    {
        $owner = $this->actingAsUser();
        $this->seedOneOfEach($owner);

        $response = $this->getJson('/api/search?year_min=1900&year_max=1930');

        $response->assertOk();
        $this->assertSame(['Metropolis'], $this->titles($response));
    }

    public function test_page_count_range_filter(): void
    {
        $owner = $this->actingAsUser();
        $this->seedOneOfEach($owner);

        $response = $this->getJson('/api/search?page_count_min=500');

        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    public function test_disc_count_range_filter_excludes_books(): void
    {
        $owner = $this->actingAsUser();
        $this->seedOneOfEach($owner);

        $response = $this->getJson('/api/search?disc_count_min=2');

        $response->assertOk();
        $this->assertSame(['Metropolis'], $this->titles($response));
    }

    /** CD's runtime_seconds is converted to minutes for the filter (3060s = 51min); DVD's runtime_minutes is used as-is. */
    public function test_runtime_range_filter_converts_cd_seconds_to_minutes(): void
    {
        $owner = $this->actingAsUser();
        $this->seedOneOfEach($owner);

        $response = $this->getJson('/api/search?runtime_min=50&runtime_max=52');

        $response->assertOk();
        $this->assertSame(['OK Computer'], $this->titles($response));
    }

    public function test_combining_a_query_with_a_filter_applies_both_as_and(): void
    {
        $owner = $this->actingAsUser();
        $this->seedOneOfEach($owner);

        // "Dune" matches the query, but the price filter excludes it.
        $response = $this->getJson('/api/search?query=Dune&price_max=5');

        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    /** `field=tracks` (GitHub issue #73's field-specific search scope, echoing #72's earlier "nur in Tracks suchen" idea from the issue's own "Bezug" section) has no plain SEARCHABLE_COLUMNS at all — only the JSON_ARRAY_SEARCHABLE_FIELDS match applies. */
    public function test_field_tracks_matches_only_a_track_title_not_the_album_title(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'CDs', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        MediaCd::query()->create([
            'library_id' => $library->id, 'title' => 'OK Computer', 'ean' => '9780000000001',
            'tracks' => [['position' => '1', 'title' => 'Airbag', 'duration_seconds' => 284]],
        ]);

        $matchesTrack = $this->getJson('/api/search?query=Airbag&field=tracks');
        $matchesTrack->assertOk();
        $this->assertCount(1, $matchesTrack->json());

        // The album title itself must NOT match under field=tracks — that's title/all's job, not tracks'.
        $matchesAlbumTitle = $this->getJson('/api/search?query=OK Computer&field=tracks');
        $matchesAlbumTitle->assertOk();
        $this->assertCount(0, $matchesAlbumTitle->json());
    }
}
