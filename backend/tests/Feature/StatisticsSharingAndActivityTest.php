<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\LibraryShare;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #74's two Statistics additions — StatisticsService::
 * sharingFor()/userActivityFor() (see that service's docblock for why
 * these live in Statistics rather than ReportsService).
 */
class StatisticsSharingAndActivityTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_sharing_overview_reports_share_scope_and_access_level(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Shared Stash', 'media_type' => 'book', 'owner_id' => $owner->id]);
        LibraryShare::query()->create(['library_id' => $library->id, 'scope' => 'all_users', 'access_level' => 'write']);

        $response = $this->getJson('/api/statistics/sharing');

        $response->assertOk();
        $row = collect($response->json())->firstWhere('library_id', $library->id);
        $this->assertTrue($row['is_shared']);
        $this->assertSame(1, $row['share_count']);
        $this->assertSame('all_users', $row['shares'][0]['scope']);
        $this->assertSame('write', $row['shares'][0]['access_level']);
    }

    /** LibraryController::show() already treats a library's share list as canWrite()-only ("no business learning who else it's shared with") — sharingFor() must apply the same restriction. */
    public function test_sharing_overview_omits_a_library_the_user_can_only_read_not_manage(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Owned By Someone Else', 'media_type' => 'book', 'owner_id' => $owner->id]);
        LibraryShare::query()->create(['library_id' => $library->id, 'scope' => 'all_users']);
        $this->actingAsUser();

        $response = $this->getJson('/api/statistics/sharing');

        $response->assertOk();
        $this->assertFalse(collect($response->json())->contains('library_id', $library->id));
    }

    public function test_sharing_overview_includes_every_library_for_an_admin(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Someone Elses Library', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $this->actingAsUser('admin');

        $response = $this->getJson('/api/statistics/sharing');

        $response->assertOk();
        $this->assertTrue(collect($response->json())->contains('library_id', $library->id));
    }

    public function test_user_activity_counts_items_per_capturing_user(): void
    {
        $owner = $this->actingAsUser();
        $other = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Books', 'media_type' => 'book', 'owner_id' => $owner->id]);
        LibraryShare::query()->create(['library_id' => $library->id, 'scope' => 'all_users']);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'A', 'ean' => '9780000000001', 'captured_by_user_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'B', 'ean' => '9780000000002', 'captured_by_user_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'C', 'ean' => '9780000000003', 'captured_by_user_id' => $other->id]);

        $response = $this->getJson('/api/statistics/user-activity');

        $response->assertOk();
        $rows = collect($response->json());
        $this->assertSame(2, $rows->firstWhere('user_id', $owner->id)['item_count']);
        $this->assertSame(1, $rows->firstWhere('user_id', $other->id)['item_count']);
    }

    /** An item captured before GitHub issue #74 shipped has no captured_by_user_id — it must still be counted, not silently dropped. */
    public function test_user_activity_groups_items_with_no_captured_by_under_a_null_user(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Books', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Pre-existing', 'ean' => '9780000000001']);

        $response = $this->getJson('/api/statistics/user-activity');

        $response->assertOk();
        $unknown = collect($response->json())->firstWhere('user_id', null);
        $this->assertNotNull($unknown);
        $this->assertSame(1, $unknown['item_count']);
    }
}
