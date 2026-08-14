<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
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

    public function test_schedule_run_skips_the_cleanup_when_disabled_via_system_settings(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('covers/book/orphan.jpg', 'bytes');
        SystemSetting::set('covers.cleanup_enabled', false);
        Carbon::setTestNow(Carbon::parse('2026-08-14 03:45:00'));

        Artisan::call('schedule:run');

        Storage::disk('local')->assertExists('covers/book/orphan.jpg');
    }

    public function test_schedule_run_still_cleans_up_when_explicitly_re_enabled(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('covers/book/orphan.jpg', 'bytes');
        SystemSetting::set('covers.cleanup_enabled', true);
        Carbon::setTestNow(Carbon::parse('2026-08-14 03:45:00'));

        Artisan::call('schedule:run');

        Storage::disk('local')->assertMissing('covers/book/orphan.jpg');
    }

    /** The setting only gates the *scheduled* run — a manual CLI invocation always cleans up, the same exemption a manually-triggered backup gets from the automatic retention policy. */
    public function test_manual_command_invocation_ignores_the_disabled_setting(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('covers/book/orphan.jpg', 'bytes');
        SystemSetting::set('covers.cleanup_enabled', false);

        Artisan::call('medinv:cleanup-covers');

        Storage::disk('local')->assertMissing('covers/book/orphan.jpg');
    }

    /**
     * The enabled check moved from inside the Schedule::call() closure into
     * a ->when() filter (routes/console.php), same fix as the backup
     * schedule's — a disabled setting should make schedule:run silently
     * skip the event (never even invoking the nested
     * `medinv:cleanup-covers` command), not run-and-announce a closure
     * that then immediately no-ops.
     */
    public function test_schedule_run_never_invokes_the_command_when_disabled(): void
    {
        SystemSetting::set('covers.cleanup_enabled', false);
        Carbon::setTestNow(Carbon::parse('2026-08-14 03:45:00'));

        Artisan::call('schedule:run');

        $this->assertStringNotContainsString('Cover cleanup complete', Artisan::output());
    }

    public function test_schedule_run_does_invoke_the_command_when_enabled_and_due(): void
    {
        SystemSetting::set('covers.cleanup_enabled', true);
        Carbon::setTestNow(Carbon::parse('2026-08-14 03:45:00'));

        Artisan::call('schedule:run');

        $this->assertStringContainsString('Cover cleanup complete', Artisan::output());
    }
}
