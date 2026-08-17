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
 * system_settings table yet) — the actual due-check is deferred into a
 * ->when() filter instead, using the same CronExpression class the
 * scheduler itself is built on, so nothing here touches the database until
 * `schedule:run` actually evaluates it (same deferred-closure property the
 * old in-body check had, see docker/supervisord.conf's scheduler loop for
 * what actually invokes `schedule:run` periodically — no cron daemon in
 * this image).
 *
 * The check lives in ->when() rather than inside the Schedule::call()
 * closure specifically so a non-due minute never "runs" the event at all:
 * Laravel's own scheduler only announces/logs a task
 * (`Running [medinv-scheduled-backup] .. DONE`) for events that pass their
 * filters, so with the check inside the closure body every single minute
 * printed that line even on the 1439 minutes/day nothing actually
 * happened. Moving the check into ->when() means schedule:run silently
 * skips the event instead, and only logs when a backup is genuinely
 * created — the other two schedules below don't need this same treatment
 * since ->dailyAt() already makes them due only once a day at the
 * Laravel-scheduler level, not once a minute.
 */
$backupService = app(BackupService::class);

Schedule::call(fn () => $backupService->create('automatic', SystemSetting::get('backup.interval_mode', 'daily'), 'scheduled'))
    ->everyMinute()
    ->when(function () use ($backupService) {
        // Explicit Carbon::now(), not the (new CronExpression(...))->isDue() default of
        // 'now' — the latter constructs a plain DateTime internally, which does not
        // respect Carbon::setTestNow() and made this untestable/silently always
        // real-time otherwise.
        return (new CronExpression($backupService->scheduledBackupCronExpression()))->isDue(Carbon::now());
    })
    ->name('medinv-scheduled-backup')
    ->withoutOverlapping()
    ->onFailure(fn () => Log::error('Scheduled backup failed — see the exception logged above.'));

/**
 * Daily orphaned-cover-file cleanup (CleanupOrphanedCoversCommand,
 * CoverCleanupService), admin-toggleable via `covers.cleanup_enabled`
 * (AdminSettingsController::updateCoverCleanup(), default enabled). Unlike
 * the backup schedule above, the *cadence* here isn't admin-configurable,
 * so no deferred-due-check dance is needed for that part — just a plain
 * ->dailyAt(). 03:45, shortly after the backup schedule's fixed 03:00 hour
 * (BackupService::scheduledBackupCronExpression()), to avoid the (harmless
 * either way, but tidier) overlap of both jobs starting in the same minute.
 * The enabled/disabled check itself is still deferred into the closure
 * (not evaluated at registration time) for the same reason the backup
 * schedule's cron lookup is: this file loads on every console bootstrap,
 * including `migrate --force` on a brand new database with no
 * system_settings table yet.
 *
 * The setting only gates this *scheduled* run, deliberately — running
 * `php artisan medinv:cleanup-covers` by hand always cleans up regardless,
 * the same way a manually-triggered backup is exempt from the automatic
 * backup retention policy (BackupService::prune()): an explicit, deliberate
 * action isn't what the "disable the automatic job" setting is for.
 *
 * Schedule::call(fn () => Artisan::call(...)) rather than the more obvious
 * Schedule::command('medinv:cleanup-covers') — the latter shells out to a
 * brand new `php artisan ...` process (visible as a literal CLI string on
 * the registered Event), which in tests runs against its own separate
 * process state and therefore never touches this test's Storage::fake()
 * disk, making the schedule itself untestable via `schedule:run`. Calling
 * Artisan::call() from a closure runs in-process instead, same reasoning
 * the backup schedule above already followed for its own Schedule::call().
 *
 * The enabled check lives in ->when() rather than inside the closure, same
 * reasoning as the backup schedule above: a disabled setting should make
 * `schedule:run` silently skip the event, not run-and-announce a closure
 * that then immediately does nothing.
 */
Schedule::call(fn () => Artisan::call('medinv:cleanup-covers'))
    ->dailyAt('03:45')
    ->when(fn () => SystemSetting::get('covers.cleanup_enabled', true))
    ->name('medinv-cover-cleanup')
    ->withoutOverlapping()
    ->onFailure(fn () => Log::error('Cover cleanup failed — see the exception logged above.'));

/**
 * Daily library value snapshot (briefing 14. "Zeitlicher Zuwachs des
 * Bestands", GitHub issue #30) — feeds StatisticsService::valueHistoryFor()
 * the "real" half of its combined history (see that method's docblock for
 * how it's merged with the created_at-derived approximation used for
 * everything before this feature existed). Not admin-toggleable like the
 * cover cleanup job above — there's no setting to gate it against, it's
 * meant to always run. 03:15, ahead of both the backup schedule's fixed
 * 03:00 hour (BackupService::scheduledBackupCronExpression()) and the cover
 * cleanup's 03:45, so this job's own database reads aren't racing either of
 * them, though none of the three would actually conflict if they did land
 * in the same minute.
 */
Schedule::call(fn () => Artisan::call('medinv:snapshot-library-values'))
    ->dailyAt('03:15')
    ->name('medinv-library-value-snapshot')
    ->withoutOverlapping()
    ->onFailure(fn () => Log::error('Library value snapshot failed — see the exception logged above.'));

/**
 * Daily failed-login-record sweep (GitHub issue #84 — a privacy-review
 * finding, not a feature briefing chapter: login_attempts had no removal
 * path at all beyond clearFailures() clearing a given email's own rows on
 * that same email's next successful login, so a row for an email that
 * never logs in successfully again — a typo, or a scanning/enumeration
 * attempt — retained that email address and IP indefinitely). See
 * BruteForceProtection::RETENTION_DAYS' docblock for the retention period
 * itself. Not admin-toggleable, same reasoning as the library value
 * snapshot job above — there's no legitimate reason to disable pruning
 * data past its own stated retention purpose. 03:30, between the library
 * value snapshot's 03:15 and the cover cleanup's 03:45, so none of these
 * jobs' own database reads race each other, though none would actually
 * conflict if they did land in the same minute.
 */
Schedule::call(fn () => Artisan::call('medinv:cleanup-login-attempts'))
    ->dailyAt('03:30')
    ->name('medinv-login-attempt-cleanup')
    ->withoutOverlapping()
    ->onFailure(fn () => Log::error('Login attempt cleanup failed — see the exception logged above.'));
