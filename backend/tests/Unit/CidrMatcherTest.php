<?php

namespace Tests\Unit;

use App\Domain\Security\CidrMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * GitHub issue #4: MEDINV_TRUSTEDIP used to only support a single IP or a
 * `*`-wildcard glob — this covers the real CIDR-range matching that
 * replaced it (App\Domain\Security\BruteForceProtection::requestFromTrustedIp()).
 */
class CidrMatcherTest extends TestCase
{
    #[DataProvider('matchingCases')]
    public function test_matches_expected_ranges(string $ip, string $range, bool $expected): void
    {
        $this->assertSame($expected, CidrMatcher::matches($ip, $range));
    }

    public static function matchingCases(): array
    {
        return [
            'exact IPv4 match' => ['192.168.1.5', '192.168.1.5', true],
            'exact IPv4 non-match' => ['192.168.1.6', '192.168.1.5', false],
            'IPv4 /24 inside range' => ['192.168.1.200', '192.168.1.0/24', true],
            'IPv4 /24 outside range' => ['192.168.2.1', '192.168.1.0/24', false],
            'IPv4 /20 partial-byte boundary, inside' => ['10.0.15.255', '10.0.0.0/20', true],
            'IPv4 /20 partial-byte boundary, outside' => ['10.0.16.0', '10.0.0.0/20', false],
            'IPv4 /32 explicit, exact match' => ['10.0.0.5', '10.0.0.5/32', true],
            'IPv4 /32 explicit, non-match' => ['10.0.0.6', '10.0.0.5/32', false],
            'IPv4 /0 matches everything' => ['1.2.3.4', '0.0.0.0/0', true],
            'exact IPv6 match' => ['::1', '::1', true],
            'exact IPv6 non-match' => ['::2', '::1', false],
            'IPv6 /64 inside range' => ['2001:db8::1', '2001:db8::/64', true],
            'IPv6 /64 outside range' => ['2001:db9::1', '2001:db8::/64', false],
            'IPv4 address against IPv6 range never matches' => ['192.168.1.1', '::1/128', false],
            'IPv6 address against IPv4 range never matches' => ['::1', '192.168.1.0/24', false],
            'invalid ip is never a match' => ['not-an-ip', '192.168.1.0/24', false],
            'invalid range is never a match' => ['192.168.1.1', 'not-a-range', false],
            'prefix length beyond address width never matches' => ['192.168.1.1', '192.168.1.0/33', false],
        ];
    }

    public function test_matches_any_accepts_a_comma_separated_list(): void
    {
        $trusted = ' 10.0.0.5 , 192.168.1.0/24, ::1 ';

        $this->assertTrue(CidrMatcher::matchesAny('10.0.0.5', $trusted));
        $this->assertTrue(CidrMatcher::matchesAny('192.168.1.42', $trusted));
        $this->assertTrue(CidrMatcher::matchesAny('::1', $trusted));
        $this->assertFalse(CidrMatcher::matchesAny('203.0.113.1', $trusted));
    }

    public function test_matches_any_with_empty_string_matches_nothing(): void
    {
        $this->assertFalse(CidrMatcher::matchesAny('10.0.0.5', ''));
    }
}
