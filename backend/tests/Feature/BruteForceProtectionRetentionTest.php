<?php

namespace Tests\Feature;

use App\Domain\Security\BruteForceProtection;
use App\Models\LoginAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BruteForceProtection::pruneOldFailures() (GitHub issue #84) in isolation
 * — CleanupLoginAttemptsCommandTest covers the command/schedule wrapper
 * around it. See RETENTION_DAYS' own docblock for why this exists:
 * clearFailures() only clears a given email's rows on that same email's
 * next successful login, so a row for an email that never logs in
 * successfully again had no removal path at all before this.
 */
class BruteForceProtectionRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function createAttempt(string $email, Carbon $attemptedAt): LoginAttempt
    {
        return LoginAttempt::query()->create(['email' => $email, 'ip_address' => '203.0.113.1', 'attempted_at' => $attemptedAt]);
    }

    public function test_deletes_records_older_than_the_retention_period(): void
    {
        $old = $this->createAttempt('old@example.com', now()->subDays(31));

        $deleted = app(BruteForceProtection::class)->pruneOldFailures();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing((new LoginAttempt)->getTable(), ['id' => $old->id]);
    }

    public function test_keeps_records_within_the_retention_period(): void
    {
        $recent = $this->createAttempt('recent@example.com', now()->subDays(29));

        $deleted = app(BruteForceProtection::class)->pruneOldFailures();

        $this->assertSame(0, $deleted);
        $this->assertDatabaseHas((new LoginAttempt)->getTable(), ['id' => $recent->id]);
    }

    /**
     * The core scenario the issue itself describes: an email that never
     * logs in successfully again (a typo, or a scanning/enumeration
     * attempt) has no other removal path — clearFailures() is never
     * called for it since that only runs on a *successful* login by that
     * same email.
     */
    public function test_removes_attempts_for_an_email_that_never_logs_in_successfully(): void
    {
        $neverSucceeds = $this->createAttempt('typo-or-scanner@example.com', now()->subDays(90));

        app(BruteForceProtection::class)->pruneOldFailures();

        $this->assertDatabaseMissing((new LoginAttempt)->getTable(), ['id' => $neverSucceeds->id]);
    }

    public function test_a_deleted_users_old_failed_attempts_are_pruned_too(): void
    {
        // pruneOldFailures() doesn't join against `users` at all — it prunes by
        // age regardless of whether the email still belongs to an existing
        // account, which is exactly what closes the "deleted account's old
        // login_attempts rows outlive the account" gap the issue describes.
        $orphaned = $this->createAttempt('deleted-account@example.com', now()->subDays(31));

        $deleted = app(BruteForceProtection::class)->pruneOldFailures();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing((new LoginAttempt)->getTable(), ['id' => $orphaned->id]);
    }
}
