<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupService;
use App\Models\Backup;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GitHub issue #27: backup.interval_mode/cron_expression were fully
 * configurable via the admin UI, but nothing ever actually triggered a
 * backup on that schedule — no Schedule:: task existed at all, and the
 * Docker image had no cron daemon to invoke `schedule:run` in the first
 * place (see docker/supervisord.conf's new [program:scheduler]). This
 * covers the Laravel-scheduler half (routes/console.php); the supervisord
 * loop that actually calls `schedule:run` periodically can't be exercised
 * by PHPUnit and was verified manually instead.
 */
class ScheduledBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cron_expression_matches_the_configured_interval_mode(): void
    {
        $service = app(BackupService::class);

        SystemSetting::set('backup.interval_mode', 'daily');
        $this->assertSame('0 3 * * *', $service->scheduledBackupCronExpression());

        SystemSetting::set('backup.interval_mode', 'weekly');
        $this->assertSame('0 3 * * 0', $service->scheduledBackupCronExpression());

        SystemSetting::set('backup.interval_mode', 'monthly');
        $this->assertSame('0 3 1 * *', $service->scheduledBackupCronExpression());

        SystemSetting::set('backup.interval_mode', 'cron');
        SystemSetting::set('backup.cron_expression', '*/15 * * * *');
        $this->assertSame('*/15 * * * *', $service->scheduledBackupCronExpression());
    }

    public function test_an_empty_cron_expression_falls_back_to_the_default_time(): void
    {
        SystemSetting::set('backup.interval_mode', 'cron');
        SystemSetting::set('backup.cron_expression', '');

        $this->assertSame('0 3 * * *', app(BackupService::class)->scheduledBackupCronExpression());
    }

    public function test_schedule_run_creates_a_backup_when_the_daily_slot_is_due(): void
    {
        Storage::fake('local');
        SystemSetting::set('backup.interval_mode', 'daily');
        Carbon::setTestNow(Carbon::parse('2026-08-14 03:00:00'));

        Artisan::call('schedule:run');

        $this->assertDatabaseHas((new Backup)->getTable(), ['trigger' => 'automatic', 'interval_mode' => 'daily']);
    }

    public function test_schedule_run_does_nothing_outside_the_due_minute(): void
    {
        Storage::fake('local');
        SystemSetting::set('backup.interval_mode', 'daily');
        Carbon::setTestNow(Carbon::parse('2026-08-14 14:00:00'));

        Artisan::call('schedule:run');

        $this->assertSame(0, Backup::query()->count());
    }

    public function test_schedule_run_respects_a_changed_interval_mode_without_a_restart(): void
    {
        Storage::fake('local');
        SystemSetting::set('backup.interval_mode', 'weekly');
        // 2026-08-16 is a Sunday — matches the weekly cron's "* * 0" day-of-week.
        Carbon::setTestNow(Carbon::parse('2026-08-16 03:00:00'));

        Artisan::call('schedule:run');

        $this->assertDatabaseHas((new Backup)->getTable(), ['trigger' => 'automatic', 'interval_mode' => 'weekly']);
    }
}
