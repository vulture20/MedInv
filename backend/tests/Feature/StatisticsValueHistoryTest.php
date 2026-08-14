<?php

namespace Tests\Feature;

use App\Domain\Statistics\StatisticsService;
use App\Models\Library;
use App\Models\LibraryShare;
use App\Models\LibraryValueSnapshot;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GitHub issue #30: StatisticsService::valueHistoryFor() combines real
 * daily snapshots (SnapshotLibraryValuesCommandTest covers the write side,
 * snapshotAll()) with a created_at-derived approximation for the period
 * before any snapshot exists. Covers the merge/cutover logic and that
 * visibility is scoped through LibraryAccessService like every other
 * statistics method.
 */
class StatisticsValueHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_a_library_with_no_items_and_no_snapshots_has_an_empty_series(): void
    {
        $owner = $this->actingAsUser();
        Library::query()->create(['name' => 'Empty', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $history = app(StatisticsService::class)->valueHistoryFor($owner);

        $this->assertSame([], $history['libraries'][0]['series']);
        $this->assertNull($history['cutover_date']);
    }

    public function test_before_any_real_snapshot_exists_the_whole_series_is_estimated_from_created_at(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        Carbon::setTestNow(Carbon::parse('2026-08-01'));
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'A', 'ean' => '1000000000001', 'price' => '10.00']);

        Carbon::setTestNow(Carbon::parse('2026-08-03'));
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'B', 'ean' => '1000000000002', 'price' => '5.50']);
        Carbon::setTestNow();

        $history = app(StatisticsService::class)->valueHistoryFor($owner);
        $series = $history['libraries'][0]['series'];

        $this->assertNull($history['cutover_date']);
        $this->assertSame([
            ['date' => '2026-08-01', 'item_count' => 1, 'total_value' => 10.0, 'source' => 'estimated'],
            ['date' => '2026-08-03', 'item_count' => 2, 'total_value' => 15.5, 'source' => 'estimated'],
        ], $series);
    }

    public function test_after_a_real_snapshot_the_estimate_only_covers_dates_before_the_cutover(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        Carbon::setTestNow(Carbon::parse('2026-08-01'));
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'A', 'ean' => '1000000000001', 'price' => '10.00']);

        // The daily job ran on 2026-08-10 and snapshotted 1 item / 10.00.
        LibraryValueSnapshot::query()->create([
            'library_id' => $library->id, 'snapshot_date' => '2026-08-10', 'item_count' => 1, 'total_value' => '10.00',
        ]);

        // An item added *after* the snapshot but before the next one hasn't been
        // snapshotted yet — expected latency of a once-a-day job, not a bug.
        Carbon::setTestNow(Carbon::parse('2026-08-12'));
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'B', 'ean' => '1000000000002', 'price' => '5.50']);
        Carbon::setTestNow();

        $history = app(StatisticsService::class)->valueHistoryFor($owner);
        $series = $history['libraries'][0]['series'];

        $this->assertSame('2026-08-10', $history['cutover_date']);
        $this->assertSame([
            ['date' => '2026-08-01', 'item_count' => 1, 'total_value' => 10.0, 'source' => 'estimated'],
            ['date' => '2026-08-10', 'item_count' => 1, 'total_value' => 10.0, 'source' => 'snapshot'],
        ], $series);
    }

    public function test_accumulated_series_sums_across_visible_libraries_carrying_each_forward(): void
    {
        $owner = $this->actingAsUser();
        $libraryA = Library::query()->create(['name' => 'A', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $libraryB = Library::query()->create(['name' => 'B', 'media_type' => 'book', 'owner_id' => $owner->id]);

        Carbon::setTestNow(Carbon::parse('2026-08-01'));
        MediaBook::query()->create(['library_id' => $libraryA->id, 'title' => 'A1', 'ean' => '1000000000001', 'price' => '10.00']);

        Carbon::setTestNow(Carbon::parse('2026-08-05'));
        MediaBook::query()->create(['library_id' => $libraryB->id, 'title' => 'B1', 'ean' => '1000000000002', 'price' => '20.00']);
        Carbon::setTestNow();

        $history = app(StatisticsService::class)->valueHistoryFor($owner);

        // On 2026-08-01 only library A has a point (10.00); library B's first
        // point is 2026-08-05 and must be carried forward as 0 before that,
        // not skipped.
        $this->assertSame([
            ['date' => '2026-08-01', 'item_count' => 1, 'total_value' => 10.0],
            ['date' => '2026-08-05', 'item_count' => 2, 'total_value' => 30.0],
        ], $history['accumulated']['series']);
    }

    public function test_a_library_not_visible_to_the_user_is_excluded_from_both_the_list_and_the_accumulated_series(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Private', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'A', 'ean' => '1000000000001', 'price' => '10.00']);
        $reader = $this->actingAsUser(); // no share at all

        $history = app(StatisticsService::class)->valueHistoryFor($reader);

        $this->assertSame([], $history['libraries']);
        $this->assertSame([], $history['accumulated']['series']);
    }

    public function test_a_library_shared_with_the_user_is_included(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Shared', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'A', 'ean' => '1000000000001', 'price' => '10.00']);
        LibraryShare::query()->create(['library_id' => $library->id, 'scope' => 'all_users']);
        $reader = $this->actingAsUser();

        $history = app(StatisticsService::class)->valueHistoryFor($reader);

        $this->assertCount(1, $history['libraries']);
        $this->assertSame($library->id, $history['libraries'][0]['library_id']);
    }

    public function test_endpoint_requires_authentication_and_returns_the_scoped_history(): void
    {
        $owner = $this->actingAsUser();
        Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->getJson('/api/statistics/value-history');

        $response->assertOk();
        $response->assertJsonStructure(['libraries', 'accumulated' => ['series'], 'cutover_date']);
    }
}
