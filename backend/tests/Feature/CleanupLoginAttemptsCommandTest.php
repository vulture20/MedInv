<?php

namespace Tests\Feature;

use App\Models\LoginAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `medinv:cleanup-login-attempts` (CleanupLoginAttemptsCommand) and its
 * daily schedule registration (routes/console.php) — GitHub issue #84.
 * BruteForceProtectionTest covers pruneOldFailures() itself in isolation;
 * this covers the command wrapper and that the Laravel scheduler actually
 * invokes it, same split CleanupOrphanedCoversCommandTest already uses for
 * the cover-cleanup job.
 */
class CleanupLoginAttemptsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createAttempt(string $email, Carbon $attemptedAt): LoginAttempt
    {
        return LoginAttempt::query()->create(['email' => $email, 'ip_address' => '203.0.113.1', 'attempted_at' => $attemptedAt]);
    }

    public function test_command_deletes_old_records_and_reports_the_count(): void
    {
        $old = $this->createAttempt('old@example.com', now()->subDays(31));
        $recent = $this->createAttempt('recent@example.com', now()->subDays(29));

        $exitCode = Artisan::call('medinv:cleanup-login-attempts');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('1 record(s) deleted', Artisan::output());
        $this->assertDatabaseMissing((new LoginAttempt)->getTable(), ['id' => $old->id]);
        $this->assertDatabaseHas((new LoginAttempt)->getTable(), ['id' => $recent->id]);
    }

    public function test_schedule_run_invokes_the_command_at_the_daily_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 03:30:00'));
        $old = $this->createAttempt('old@example.com', now()->subDays(31));

        Artisan::call('schedule:run');

        $this->assertDatabaseMissing((new LoginAttempt)->getTable(), ['id' => $old->id]);
    }

    public function test_schedule_run_does_nothing_outside_the_due_minute(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 14:00:00'));
        $old = $this->createAttempt('old@example.com', now()->subDays(31));

        Artisan::call('schedule:run');

        $this->assertDatabaseHas((new LoginAttempt)->getTable(), ['id' => $old->id]);
    }
}
