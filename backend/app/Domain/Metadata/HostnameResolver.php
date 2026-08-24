<?php

namespace App\Domain\Metadata;

/**
 * Thin wrapper around PHP's dns_get_record() (GitHub issue #184 — SSRF
 * guard hardening for CoverDownloadService::download()). Kept as its own
 * tiny, separately-injectable class for the exact same reason
 * CurlImageFetcher is (see that class's own docblock): a test must be able
 * to control what a hostname "resolves" to without touching real DNS,
 * which would otherwise make CoverDownloadServiceTest's fixture domains
 * (e.g. `covers.example.com`) depend on real, live DNS records — slow (or
 * outright hanging, depending on the resolver) in a network-restricted
 * test/CI environment, and liable to silently start failing if that domain
 * ever resolves to something real.
 */
class HostnameResolver
{
    /**
     * Every IPv4/IPv6 address this hostname resolves to (A + AAAA records),
     * or `[]` if resolution failed entirely (NXDOMAIN, no network, an
     * already-numeric host dns_get_record() can't look up, ...) — the
     * caller treats a failure to resolve as "unknown, not blocked" rather
     * than an error; see CoverDownloadService::isDisallowedHost()'s own
     * docblock for why that's safe.
     *
     * @return string[]
     */
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if ($records === false) {
            return [];
        }

        return collect($records)
            ->map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null)
            ->filter(fn ($ip) => is_string($ip) && $ip !== '')
            ->values()
            ->all();
    }
}
