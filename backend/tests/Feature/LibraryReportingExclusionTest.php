<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #176: exclude_from_statistics/exclude_from_reports, the two
 * toggles LibrarySettingsDialog.tsx's edit form exposes. Deliberately
 * mirrors LibraryVisibilityInSearchAndStatisticsTest's shape (one
 * representative endpoint standing in for the rest of each domain, since
 * both StatisticsService and ReportsService apply the same flag uniformly
 * across all their own methods) plus a check that neither flag affects the
 * library's own visibility (index/show/search) at all.
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

    public function test_an_owner_can_set_both_exclusion_flags_via_update(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Wishlist', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->putJson("/api/libraries/{$library->id}", [
            'exclude_from_statistics' => true,
            'exclude_from_reports' => true,
        ]);

        $response->assertOk();
        $this->assertTrue($library->fresh()->exclude_from_statistics);
        $this->assertTrue($library->fresh()->exclude_from_reports);
    }

    public function test_a_library_excluded_from_statistics_is_omitted_from_the_statistics_overview(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create([
            'name' => 'Wishlist', 'media_type' => 'book', 'owner_id' => $owner->id, 'exclude_from_statistics' => true,
        ]);

        $response = $this->getJson('/api/statistics');

        $response->assertOk();
        $this->assertFalse(collect($response->json())->contains('library_id', $library->id));
    }

    public function test_a_library_excluded_from_reports_is_omitted_from_reports_but_stays_in_statistics(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create([
            'name' => 'Loans', 'media_type' => 'book', 'owner_id' => $owner->id, 'exclude_from_reports' => true,
        ]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);

        $recentAdditions = $this->getJson('/api/reports/recent-additions')->assertOk();
        $this->assertFalse(collect($recentAdditions->json())->contains('library_id', $library->id));

        $statistics = $this->getJson('/api/statistics')->assertOk();
        $this->assertTrue(collect($statistics->json())->contains('library_id', $library->id));
    }

    public function test_a_library_excluded_from_both_still_appears_in_the_library_list_and_search(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create([
            'name' => 'StillVisible8215', 'media_type' => 'book', 'owner_id' => $owner->id,
            'exclude_from_statistics' => true, 'exclude_from_reports' => true,
        ]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'FindMeAnyway3312', 'ean' => '9780000000002']);

        $this->getJson('/api/libraries')->assertOk()->assertJsonFragment(['id' => $library->id]);

        $searchResponse = $this->getJson('/api/search?query=FindMeAnyway3312')->assertOk();
        $this->assertTrue(collect($searchResponse->json())->contains('id', $item->id));
    }
}
