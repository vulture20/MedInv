<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\MediaDvdBluray;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #73 — GET /search/filter-options populates SearchPage.tsx's
 * attribute filter <select>s from values that actually occur in the
 * visible collection, mirroring StatisticsService::distributionsFor()'s
 * own "values that actually occur" precedent (just distinct values, not
 * per-library counts).
 */
class SearchFilterOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_returns_distinct_book_attribute_values(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Books', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'genre' => 'Sci-Fi']);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Foundation', 'ean' => '9780000000002', 'genre' => 'Sci-Fi']);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Emma', 'ean' => '9780000000003', 'genre' => 'Romance']);

        $response = $this->getJson('/api/search/filter-options');

        $response->assertOk();
        $this->assertEqualsCanonicalizing(['Romance', 'Sci-Fi'], $response->json('book.genre'));
    }

    public function test_splits_a_dvd_blurays_comma_separated_languages_column(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Films', 'media_type' => 'dvd_bluray', 'owner_id' => $owner->id]);
        MediaDvdBluray::query()->create(['library_id' => $library->id, 'title' => 'Metropolis', 'ean' => '9780000000004', 'languages' => 'German, English']);

        $response = $this->getJson('/api/search/filter-options');

        $response->assertOk();
        $this->assertEqualsCanonicalizing(['English', 'German'], $response->json('dvd_bluray.languages'));
    }

    /** GitHub issue #140: `genre` now has its own distinct-values list for DVD/Blu-ray too, alongside book's own (SearchFilterPanel.tsx merges both into one combined `<select>`, the same pattern `medium` already uses for cd+dvd_bluray). */
    public function test_returns_distinct_dvd_bluray_genre_values_separately_from_books(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Films', 'media_type' => 'dvd_bluray', 'owner_id' => $owner->id]);
        MediaDvdBluray::query()->create(['library_id' => $library->id, 'title' => 'Metropolis', 'ean' => '9780000000004', 'genre' => 'Sci-Fi']);
        MediaDvdBluray::query()->create(['library_id' => $library->id, 'title' => 'Casablanca', 'ean' => '9780000000006', 'genre' => 'Drama']);

        $response = $this->getJson('/api/search/filter-options');

        $response->assertOk();
        $this->assertEqualsCanonicalizing(['Drama', 'Sci-Fi'], $response->json('dvd_bluray.genre'));
        $this->assertSame([], $response->json('book.genre'));
    }

    /** Same "not shared -> not findable" rule as search/statistics — an unshared library's genre must not leak into the options list either. */
    public function test_an_unshared_librarys_values_are_excluded(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Secret Stash', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Hidden', 'ean' => '9780000000005', 'genre' => 'VerySecretGenre']);
        $this->actingAsUser();

        $response = $this->getJson('/api/search/filter-options');

        $response->assertOk();
        $this->assertNotContains('VerySecretGenre', $response->json('book.genre'));
    }
}
