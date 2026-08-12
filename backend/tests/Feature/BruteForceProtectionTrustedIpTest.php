<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end coverage for MEDINV_TRUSTEDIP through the actual login
 * throttle (GitHub issue #4) — CidrMatcherTest covers the matching logic in
 * isolation; this confirms BruteForceProtection actually reads the env var
 * and applies it during a real login flow. Test requests come from
 * 127.0.0.1 (Laravel's HTTP testing default).
 */
class BruteForceProtectionTrustedIpTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('MEDINV_TRUSTEDIP');
        unset($_ENV['MEDINV_TRUSTEDIP'], $_SERVER['MEDINV_TRUSTEDIP']);
        parent::tearDown();
    }

    /**
     * env() (Illuminate\Support\Env) reads $_SERVER before ever falling back to
     * a live getenv()/putenv() read — Dotenv\Repository\RepositoryBuilder's
     * DEFAULT_ADAPTERS is [ServerConstAdapter, EnvConstAdapter], checked before
     * the PutenvAdapter Laravel appends after those. Since `.env` sets
     * MEDINV_TRUSTEDIP= at boot, $_SERVER already has an (empty, but "present")
     * value for it, which wins over a bare putenv() call — all three have to be
     * set for a runtime override to actually take effect.
     */
    private function setTrustedIp(string $value): void
    {
        putenv("MEDINV_TRUSTEDIP={$value}");
        $_ENV['MEDINV_TRUSTEDIP'] = $value;
        $_SERVER['MEDINV_TRUSTEDIP'] = $value;
    }

    private function failLoginRepeatedly(string $email, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->postJson('/api/login', ['email' => $email, 'password' => 'wrong-password']);
        }
    }

    public function test_a_trusted_bare_ip_is_never_locked_out(): void
    {
        $this->setTrustedIp('127.0.0.1');
        User::factory()->create(['email' => 'trusted@example.com']);
        SystemSetting::set('security.throttle_max_attempts', 3);

        $this->failLoginRepeatedly('trusted@example.com', 5);
        $response = $this->postJson('/api/login', ['email' => 'trusted@example.com', 'password' => 'wrong-password']);

        $this->assertSame('invalid_credentials', $response->json('error_code'));
    }

    public function test_a_trusted_cidr_range_covering_the_request_ip_is_never_locked_out(): void
    {
        $this->setTrustedIp('127.0.0.0/8');
        User::factory()->create(['email' => 'trusted-range@example.com']);
        SystemSetting::set('security.throttle_max_attempts', 3);

        $this->failLoginRepeatedly('trusted-range@example.com', 5);
        $response = $this->postJson('/api/login', ['email' => 'trusted-range@example.com', 'password' => 'wrong-password']);

        $this->assertSame('invalid_credentials', $response->json('error_code'));
    }

    public function test_an_untrusted_ip_gets_locked_out_as_normal(): void
    {
        User::factory()->create(['email' => 'untrusted@example.com']);
        SystemSetting::set('security.throttle_max_attempts', 3);

        $this->failLoginRepeatedly('untrusted@example.com', 3);
        $response = $this->postJson('/api/login', ['email' => 'untrusted@example.com', 'password' => 'wrong-password']);

        $this->assertSame('account_locked', $response->json('error_code'));
    }

    public function test_a_non_matching_trusted_range_still_locks_out(): void
    {
        $this->setTrustedIp('10.0.0.0/8');
        User::factory()->create(['email' => 'not-in-range@example.com']);
        SystemSetting::set('security.throttle_max_attempts', 3);

        $this->failLoginRepeatedly('not-in-range@example.com', 3);
        $response = $this->postJson('/api/login', ['email' => 'not-in-range@example.com', 'password' => 'wrong-password']);

        $this->assertSame('account_locked', $response->json('error_code'));
    }
}
