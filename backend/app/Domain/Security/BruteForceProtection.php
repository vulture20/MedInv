<?php

namespace App\Domain\Security;

use App\Models\LoginAttempt;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Str;

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
     * TODO: replace with proper CIDR range matching once MEDINV_TRUSTEDIP's
     * exact accepted format (single IP, CIDR block, comma list, ...) is
     * finalized (briefing 12.4 / 16.). Currently supports a single IP or a
     * `*`-wildcard pattern only.
     */
    private function requestFromTrustedIp(): bool
    {
        $trustedRange = env('MEDINV_TRUSTEDIP');

        if (! $trustedRange) {
            return false;
        }

        return Str::isMatch(
            '/^'.str_replace(['.', '*'], ['\.', '.*'], $trustedRange).'$/',
            (string) RequestFacade::ip(),
        );
    }
}
