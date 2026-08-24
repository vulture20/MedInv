<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\LibraryShare;
use App\Models\LibraryUserPreference;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #179: exclude_from_statistics/exclude_from_reports/
 * exclude_from_dashboard, per requesting user rather than the single global
 * flag GitHub issue #176 originally shipped (see LibraryUserPreference's own
 * docblock). Mirrors that issue's own test shape (one representative
 * endpoint standing in for the rest of each domain, since
 * StatisticsService/ReportsService/SearchService::randomItemsFor() all
 * apply the relevant flag uniformly) plus new coverage for the thing #176
 * couldn't have: two users disagreeing about the same library.
 */
class LibraryReportingExclusionTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): User
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_a_user_can_set_their_own_preference_for_a_library_they_can_read(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Wishlist', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->putJson("/api/libraries/{$library->id}/preference", [
            'exclude_from_statistics' => true,
            'exclude_from_reports' => true,
            'exclude_from_dashboard' => true,
        ]);

        $response->assertSuccessful();
        $preference = LibraryUserPreference::query()->where('library_id', $library->id)->where('user_id', $owner->id)->first();
        $this->assertTrue($preference->exclude_from_statistics);
        $this->assertTrue($preference->exclude_from_reports);
        $this->assertTrue($preference->exclude_from_dashboard);
    }

    /** A reader who neither owns nor manages the library (a plain scope=all_users share) can still set their own preference for it — this is a personal setting, not a library-management action. */
    public function test_a_non_owning_reader_can_also_set_their_own_preference(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Shared shelf', 'media_type' => 'book', 'owner_id' => $owner->id]);
        LibraryShare::query()->create(['library_id' => $library->id, 'scope' => 'all_users']);
        $reader = $this->actingAsUser();

        $response = $this->putJson("/api/libraries/{$library->id}/preference", ['exclude_from_statistics' => true]);

        $response->assertSuccessful();
        $this->assertTrue(LibraryUserPreference::query()->where('library_id', $library->id)->where('user_id', $reader->id)->first()->exclude_from_statistics);
    }

    public function test_setting_a_preference_for_an_unreadable_library_is_forbidden(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Private', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $this->actingAsUser();

        $this->putJson("/api/libraries/{$library->id}/preference", ['exclude_from_statistics' => true])->assertForbidden();
    }

    public function test_a_library_the_user_excluded_from_statistics_is_omitted_from_their_own_statistics_overview(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Wishlist', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $this->putJson("/api/libraries/{$library->id}/preference", ['exclude_from_statistics' => true])->assertSuccessful();

        $response = $this->getJson('/api/statistics');

        $response->assertOk();
        $this->assertFalse(collect($response->json())->contains('library_id', $library->id));
    }

    public function test_a_library_excluded_from_reports_is_omitted_from_reports_but_stays_in_statistics(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Loans', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $this->putJson("/api/libraries/{$library->id}/preference", ['exclude_from_reports' => true])->assertSuccessful();

        $recentAdditions = $this->getJson('/api/reports/recent-additions')->assertOk();
        $this->assertFalse(collect($recentAdditions->json())->contains('library_id', $library->id));

        $statistics = $this->getJson('/api/statistics')->assertOk();
        $this->assertTrue(collect($statistics->json())->contains('library_id', $library->id));
    }

    public function test_a_library_excluded_from_the_dashboard_is_omitted_from_random_items(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Wishlist', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $this->putJson("/api/libraries/{$library->id}/preference", ['exclude_from_dashboard' => true])->assertSuccessful();

        $response = $this->getJson('/api/dashboard/random-items');

        $response->assertOk();
        $this->assertSame([], $response->json('book'));
    }

    public function test_a_library_excluded_from_all_three_still_appears_in_the_library_list_and_search(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'StillVisible8215', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'FindMeAnyway3312', 'ean' => '9780000000002']);
        $this->putJson("/api/libraries/{$library->id}/preference", [
            'exclude_from_statistics' => true, 'exclude_from_reports' => true, 'exclude_from_dashboard' => true,
        ])->assertSuccessful();

        $this->getJson('/api/libraries')->assertOk()->assertJsonFragment(['id' => $library->id]);

        $searchResponse = $this->getJson('/api/search?query=FindMeAnyway3312')->assertOk();
        $this->assertTrue(collect($searchResponse->json())->contains('id', $item->id));
    }

    /** The core of GitHub issue #179's own request: two users sharing a library can each have a different opinion about it — one owner's exclusion choice must never leak into another user's Statistics view. */
    public function test_two_users_sharing_a_library_can_disagree_about_excluding_it(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Shared shelf', 'media_type' => 'book', 'owner_id' => $owner->id]);
        LibraryShare::query()->create(['library_id' => $library->id, 'scope' => 'all_users']);
        $other = User::factory()->create(['level' => 'user', 'is_active' => true]);

        $this->actingAs($owner);
        $this->putJson("/api/libraries/{$library->id}/preference", ['exclude_from_statistics' => true])->assertSuccessful();
        $ownerStats = $this->getJson('/api/statistics')->assertOk();
        $this->assertFalse(collect($ownerStats->json())->contains('library_id', $library->id));

        $this->actingAs($other);
        $otherStats = $this->getJson('/api/statistics')->assertOk();
        $this->assertTrue(collect($otherStats->json())->contains('library_id', $library->id));
    }

    /** GET /library-preferences (SettingsPage.tsx's own listing) — every visible library, defaulting every flag to false when no preference row exists yet. */
    public function test_library_preferences_index_lists_every_visible_library_with_defaults(): void
    {
        $owner = $this->actingAsUser();
        $untouched = Library::query()->create(['name' => 'Untouched', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $excluded = Library::query()->create(['name' => 'Excluded', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $this->putJson("/api/libraries/{$excluded->id}/preference", ['exclude_from_dashboard' => true])->assertSuccessful();

        $response = $this->getJson('/api/library-preferences')->assertOk();

        $rows = collect($response->json())->keyBy('library_id');
        $this->assertFalse($rows[$untouched->id]['exclude_from_statistics']);
        $this->assertFalse($rows[$untouched->id]['exclude_from_dashboard']);
        $this->assertTrue($rows[$excluded->id]['exclude_from_dashboard']);
        $this->assertFalse($rows[$excluded->id]['exclude_from_statistics']);
    }
}
