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
 */
class CurlImageFetcher
{
    private const TIMEOUT_SECONDS = 10;

    /** @return string|null Raw response body, or null on any non-2xx status, transport failure, or empty body. */
    public function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
        ]);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

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
