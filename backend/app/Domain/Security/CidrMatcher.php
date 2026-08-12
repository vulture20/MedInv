<?php

namespace App\Domain\Security;

/**
 * IPv4/IPv6 CIDR range matching for MEDINV_TRUSTEDIP (briefing 12.4/16.) —
 * kept as its own class rather than inlined into BruteForceProtection since
 * the accepted format (a comma-separated list of bare IPs and/or CIDR
 * blocks, IPv4 and IPv6 freely mixed, e.g. "10.0.0.5, 192.168.1.0/24, ::1")
 * has enough edge cases — missing prefix length, address-family mismatches,
 * IPv6 — to warrant independent test coverage rather than only exercising
 * it indirectly through the login-throttle flow.
 */
class CidrMatcher
{
    /**
     * @param  string  $trustedRanges  Comma-separated list of bare IPs and/or CIDR
     *                                 blocks. A bare IP (no `/prefix`) matches only
     *                                 that exact address, equivalent to /32 (IPv4) or
     *                                 /128 (IPv6).
     */
    public static function matchesAny(string $ip, string $trustedRanges): bool
    {
        foreach (explode(',', $trustedRanges) as $range) {
            $range = trim($range);
            if ($range !== '' && self::matches($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $ip, string $range): bool
    {
        [$subnet, $prefixLength] = array_pad(explode('/', $range, 2), 2, null);

        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);

        // inet_pton() returns false on an invalid address, 4 bytes for IPv4 or 16
        // for IPv6 otherwise — a length mismatch means an IPv4 address is being
        // checked against an IPv6 range or vice versa, never a match.
        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $maxPrefixLength = strlen($subnetBinary) * 8;
        $prefixLength = $prefixLength === null ? $maxPrefixLength : (int) $prefixLength;

        if ($prefixLength < 0 || $prefixLength > $maxPrefixLength) {
            return false;
        }

        $fullBytes = intdiv($prefixLength, 8);
        $remainderBits = $prefixLength % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        // Partial final byte (e.g. a /20 or /27) — compare only the top
        // $remainderBits bits of that byte.
        $mask = chr((0xFF << (8 - $remainderBits)) & 0xFF);

        return (substr($ipBinary, $fullBytes, 1) & $mask) === (substr($subnetBinary, $fullBytes, 1) & $mask);
    }
}
