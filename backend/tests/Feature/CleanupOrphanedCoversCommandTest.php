<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `medinv:cleanup-covers` (CleanupOrphanedCoversCommand) and its daily
 * schedule registration (routes/console.php). CoverCleanupServiceTest
 * covers the cleanup logic itself; this covers the command wrapper and
 * that the Laravel scheduler actually invokes it — the same gap issue #27
 * found for the backup schedule (nothing ever calling `schedule:run` isn't
 * exercisable by PHPUnit and is verified manually via
 * docker/supervisord.conf's scheduler loop, same as ScheduledBackupTest).
 */
class CleanupOrphanedCoversCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_deletes_orphaned_covers_and_reports_the_count(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('covers/book/orphan.jpg', 'bytes');

        $exitCode = Artisan::call('medinv:cleanup-covers');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('1 orphaned file(s) deleted', Artisan::output());
        Storage::disk('local')->assertMissing('covers/book/orphan.jpg');
    }

    public function test_schedule_run_invokes_the_command_at_the_daily_slot(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('covers/book/orphan.jpg', 'bytes');
        Carbon::setTestNow(Carbon::parse('2026-08-14 03:45:00'));

        Artisan::call('schedule:run');

        Storage::disk('local')->assertMissing('covers/book/orphan.jpg');
    }

    public function test_schedule_run_does_nothing_outside_the_due_minute(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('covers/book/orphan.jpg', 'bytes');
        Carbon::setTestNow(Carbon::parse('2026-08-14 14:00:00'));

        Artisan::call('schedule:run');

        Storage::disk('local')->assertExists('covers/book/orphan.jpg');
    }
}
