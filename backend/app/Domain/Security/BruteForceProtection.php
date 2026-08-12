<?php

namespace App\Domain\Security;

use App\Models\LoginAttempt;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Request as RequestFacade;

/**
 * Login throttle per briefing 12.4: default 6 failures within 5 minutes
 * locks the account for 30 minutes, both configurable via system_settings.
 * Requests from MEDINV_TRUSTEDIP are exempt.
 */
class BruteForceProtection
{
    public function isLocked(string $email): bool
    {
        if ($this->requestFromTrustedIp()) {
            return false;
        }

        [$maxAttempts, $windowMinutes, $lockMinutes] = $this->thresholds();

        $recentFailures = LoginAttempt::query()
            ->where('email', $email)
            ->where('attempted_at', '>=', now()->subMinutes($windowMinutes))
            ->count();

        if ($recentFailures < $maxAttempts) {
            return false;
        }

        $lastFailure = LoginAttempt::query()->where('email', $email)->latest('attempted_at')->value('attempted_at');

        return $lastFailure && $lastFailure->addMinutes($lockMinutes)->isFuture();
    }

    public function recordFailure(string $email): void
    {
        if ($this->requestFromTrustedIp()) {
            return;
        }

        LoginAttempt::query()->create([
            'email' => $email,
            'ip_address' => RequestFacade::ip(),
            'attempted_at' => now(),
        ]);
    }

    public function clearFailures(string $email): void
    {
        LoginAttempt::query()->where('email', $email)->delete();
    }

    /** @return array{0: int, 1: int, 2: int} [maxAttempts, windowMinutes, lockMinutes] */
    private function thresholds(): array
    {
        return [
            (int) SystemSetting::get('security.throttle_max_attempts', 6),
            (int) SystemSetting::get('security.throttle_window_minutes', 5),
            (int) SystemSetting::get('security.throttle_lock_minutes', 30),
        ];
    }

    /**
     * MEDINV_TRUSTEDIP (briefing 12.4/16.): a comma-separated list of bare
     * IPv4/IPv6 addresses and/or CIDR blocks, e.g.
     * "10.0.0.5, 192.168.1.0/24, ::1" — see CidrMatcher for the matching
     * logic itself.
     */
    private function requestFromTrustedIp(): bool
    {
        $trustedRanges = env('MEDINV_TRUSTEDIP');

        if (! $trustedRanges) {
            return false;
        }

        return CidrMatcher::matchesAny((string) RequestFacade::ip(), $trustedRanges);
    }
}
