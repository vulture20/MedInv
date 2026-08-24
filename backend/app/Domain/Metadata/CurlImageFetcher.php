<?php

namespace App\Domain\Metadata;

use Illuminate\Support\Facades\Log;

/**
 * Fetches raw bytes from an external image URL using PHP's native curl
 * extension directly, deliberately bypassing Laravel's Http (Guzzle)
 * client that CoverDownloadService otherwise uses for the metadata
 * providers' own JSON/GraphQL API calls.
 *
 * Root cause, confirmed live (a real cover import from Discogs silently
 * producing no cover): Discogs' image CDN (i.discogs.com, fronted by
 * Cloudflare) reliably returns 403 for Guzzle's requests, while an
 * otherwise-identical raw curl_exec() call to the exact same URL, at the
 * exact same moment, from the exact same network path, succeeds every
 * time. This was narrowed down by testing — one variable at a time, then
 * in combination — every plausible Guzzle-side cause (forcing HTTP/2 via
 * both the high-level `version` option and a raw `CURLOPT_HTTP_VERSION`
 * override, a custom User-Agent, explicit Accept/Accept-Encoding headers,
 * disabling automatic content-decoding) without finding a fix on Guzzle's
 * side; the block persisted even once packet captures confirmed Guzzle
 * really was negotiating HTTP/2 exactly like the working raw-curl
 * request. That points at a TLS/HTTP2-level client fingerprint (JA3/JA4
 * or similar) Cloudflare's bot management distinguishes on, below the
 * level any Guzzle request option controls — not something fixable by
 * tuning headers.
 *
 * Kept as its own tiny, separately-injectable class (rather than inlined
 * into CoverDownloadService) specifically so tests can mock fetch()
 * directly — Http::fake() cannot intercept a raw curl_exec() call at all,
 * so there would otherwise be no way to unit-test CoverDownloadService's
 * download() without a real network call on every test run.
 *
 * GitHub issue #46: because this bypasses the `Http` facade, it also
 * bypasses AppServiceProvider::logOutgoingHttpRequests()'s global Guzzle
 * hook — cover downloads used to be the one outgoing-HTTP path in this app
 * with no DEBUG visibility at all, success or failure. fetch() now logs
 * every attempt itself, in the same shape (method/url/status/duration_ms)
 * that hook already uses for every other outgoing call, so a cover
 * download shows up in the log the same way a metadata lookup does.
 */
class CurlImageFetcher
{
    private const TIMEOUT_SECONDS = 10;

    /**
     * @param  string[]  $resolveToIps  GitHub issue #83: the exact, already-
     *                                  validated public IP(s) CoverDownloadService's
     *                                  SSRF guard resolved `$url`'s host to
     *                                  (empty for a literal-IP URL or an
     *                                  unresolvable hostname — nothing to
     *                                  pin either way, see
     *                                  CoverDownloadService::
     *                                  resolveAndValidateHost()'s docblock).
     *                                  When non-empty, the real connection
     *                                  is pinned to exactly these addresses
     *                                  via CURLOPT_RESOLVE instead of
     *                                  letting curl resolve the hostname
     *                                  itself at connect time — without
     *                                  this, a second, independent DNS
     *                                  lookup happening here, after the
     *                                  caller's own check already passed,
     *                                  would reopen the exact DNS-rebinding
     *                                  gap that check exists to close (a
     *                                  short-TTL attacker-controlled domain
     *                                  could answer the two lookups
     *                                  differently). CURLOPT_RESOLVE only
     *                                  redirects which IP the TCP
     *                                  connection is made to — it does not
     *                                  touch the Host header or the TLS SNI
     *                                  hostname sent for the handshake, so
     *                                  HTTPS certificate validation against
     *                                  the original hostname is unaffected.
     * @return string|null Raw response body, or null on any non-2xx status, transport failure, or empty body.
     */
    public function fetch(string $url, array $resolveToIps = []): ?string
    {
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            // GitHub issue #184: deliberately does NOT follow redirects.
            // CoverDownloadService::download() validates $url's own host
            // against the SSRF guard before ever calling fetch() — letting
            // curl transparently follow a redirect here would let a
            // response from an already-validated *public* host retarget
            // the actual request to an internal address (e.g. a 302 to
            // http://169.254.169.254/...) with no re-validation at all. A
            // provider whose cover URL happens to redirect simply gets
            // treated as a failed fetch (this class's own "best effort"
            // framing, see this class's docblock) rather than transparently
            // followed.
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($resolveToIps !== []) {
            $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
            $scheme = parse_url($url, PHP_URL_SCHEME) ?? 'http';
            $port = parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80);
            // "host:port:ip1,ip2,..." — curl's own documented CURLOPT_RESOLVE
            // syntax for pinning a hostname to one or more literal addresses,
            // taking priority over its normal DNS resolution for this request.
            $curlOptions[CURLOPT_RESOLVE] = ["{$host}:{$port}:".implode(',', $resolveToIps)];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $curlOptions);

        $startedAt = microtime(true);
        $body = curl_exec($ch);
        $durationMs = (microtime(true) - $startedAt) * 1000;
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);

        // Logged unconditionally, success or failure — mirrors
        // logOutgoingHttpRequests()'s on_stats hook firing for every
        // request regardless of outcome. Unlike that hook's
        // `response_body` (truncated JSON/text a human can actually read),
        // this logs the image's size/declared content type instead of its
        // raw bytes — a cover's body isn't text, and dumping it into a log
        // line would be both useless and needlessly large.
        Log::debug('Outgoing HTTP request/response (cover download)', [
            'method' => 'GET',
            'url' => $url,
            'status' => $body !== false ? $status : null,
            'duration_ms' => round($durationMs),
            'content_type' => $body !== false ? ($contentType ?: null) : null,
            'bytes' => $body !== false ? strlen($body) : null,
        ]);

        if ($body === false) {
            Log::info('Cover download failed.', ['url' => $url, 'error' => $error]);

            return null;
        }

        if ($status < 200 || $status >= 300 || $body === '') {
            return null;
        }

        return $body;
    }
}
