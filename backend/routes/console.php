<?php

use App\Domain\Backup\BackupService;
use App\Models\SystemSetting;
use Cron\CronExpression;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Automatic backups per the admin-configured interval (briefing 9.2) — the
 * missing piece behind GitHub issue #27: backup.interval_mode/cron_expression
 * were fully configurable via the admin UI (AdminSettingsController::updateBackup())
 * but nothing ever actually triggered a backup on that schedule.
 *
 * Registered as ->everyMinute() rather than a dynamic ->cron($expression) —
 * this file is loaded on *every* console bootstrap (any artisan command,
 * not just `schedule:run`), so anything evaluated here at registration time
 * runs before `RefreshDatabase`/a fresh install's migrations exist. An
 * earlier version called SystemSetting::get() directly in ->cron(...) and
 * broke `php artisan migrate --force` itself on a brand new database (no
 * system_settings table yet) — the actual due-check is deferred into the
 * closure instead, using the same CronExpression class the scheduler
 * itself is built on, so nothing here touches the database until the
 * closure actually runs (and `schedule:run` only invokes due closures, so
 * this doesn't create a backup attempt every single minute — it just
 * *checks* every minute, same resolution the scheduler offers regardless).
 * See docker/supervisord.conf's scheduler loop for what actually invokes
 * `schedule:run` periodically (no cron daemon in this image).
 */
$backupService = app(BackupService::class);

Schedule::call(function () use ($backupService) {
    $cron = $backupService->scheduledBackupCronExpression();

    // Explicit Carbon::now(), not the (new CronExpression(...))->isDue() default of
    // 'now' — the latter constructs a plain DateTime internally, which does not
    // respect Carbon::setTestNow() and made this untestable/silently always
    // real-time otherwise.
    if (! (new CronExpression($cron))->isDue(Carbon::now())) {
        return;
    }

    $backupService->create('automatic', SystemSetting::get('backup.interval_mode', 'daily'));
})
    ->everyMinute()
    ->name('medinv-scheduled-backup')
    ->withoutOverlapping()
    ->onFailure(fn () => Log::error('Scheduled backup failed — see the exception logged above.'));
