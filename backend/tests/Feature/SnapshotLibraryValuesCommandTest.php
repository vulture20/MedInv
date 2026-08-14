<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\LibraryValueSnapshot;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `medinv:snapshot-library-values` (SnapshotLibraryValuesCommand) and its
 * daily schedule registration (routes/console.php) — GitHub issue #30's
 * "real snapshot" half. StatisticsServiceValueHistoryTest covers
 * valueHistoryFor()'s read-side merging logic; this covers the command
 * wrapper, that snapshotAll() writes the right numbers, and that the
 * Laravel scheduler actually invokes it daily, same pattern
 * ScheduledBackupTest/CleanupOrphanedCoversCommandTest already established.
 */
class SnapshotLibraryValuesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_snapshots_every_librarys_item_count_and_total_value(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'A', 'ean' => '1000000000001', 'price' => '10.00']);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'B', 'ean' => '1000000000002', 'price' => '5.50']);
        $empty = Library::query()->create(['name' => 'Empty shelf', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $exitCode = Artisan::call('medinv:snapshot-library-values');

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas((new LibraryValueSnapshot)->getTable(), [
            'library_id' => $library->id,
            'item_count' => 2,
            'total_value' => '15.50',
        ]);
        $this->assertDatabaseHas((new LibraryValueSnapshot)->getTable(), [
            'library_id' => $empty->id,
            'item_count' => 0,
            'total_value' => '0.00',
        ]);
    }

    public function test_running_the_command_twice_the_same_day_overwrites_rather_than_duplicates(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'A', 'ean' => '1000000000001', 'price' => '10.00']);

        Artisan::call('medinv:snapshot-library-values');
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'B', 'ean' => '1000000000002', 'price' => '5.00']);
        Artisan::call('medinv:snapshot-library-values');

        $this->assertSame(1, LibraryValueSnapshot::query()->where('library_id', $library->id)->count());
        $this->assertDatabaseHas((new LibraryValueSnapshot)->getTable(), [
            'library_id' => $library->id,
            'item_count' => 2,
            'total_value' => '15.00',
        ]);
    }

    public function test_schedule_run_invokes_the_command_at_the_daily_slot(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        Carbon::setTestNow(Carbon::parse('2026-08-14 03:15:00'));

        Artisan::call('schedule:run');

        $this->assertDatabaseHas((new LibraryValueSnapshot)->getTable(), ['library_id' => $library->id]);
    }

    public function test_schedule_run_does_nothing_outside_the_due_minute(): void
    {
        Library::query()->create([
            'name' => 'Novels', 'media_type' => 'book',
            'owner_id' => User::factory()->create(['level' => 'user', 'is_active' => true])->id,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-14 14:00:00'));

        Artisan::call('schedule:run');

        $this->assertSame(0, LibraryValueSnapshot::query()->count());
    }
}
