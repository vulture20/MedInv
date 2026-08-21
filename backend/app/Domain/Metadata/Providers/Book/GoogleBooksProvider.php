<?php

namespace App\Domain\Metadata\Providers\Book;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderConfigField;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\Contracts\TestableMetadataProvider;
use App\Domain\Metadata\MetadataProviderRequestException;
use App\Models\MetadataPlugin;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google Books metadata plugin (briefing 8.2 — Buch, GitHub issue #20).
 * Unlike OpenLibraryProvider (truly open, no key concept at all), the
 * Volumes API (https://www.googleapis.com/books/v1/volumes) is reachable
 * without any credentials but only under a very small shared daily quota —
 * confirmed live during implementation: unauthenticated requests from this
 * environment immediately returned HTTP 429
 * ("Quota exceeded ... consumer 'project_number:...'"), matching Google's
 * own documentation, which recommends an API key for anything beyond
 * occasional testing. `configFields()` therefore declares an *optional*
 * `api_key` (unlike UpcMdbProvider's, which is required and the request
 * can't be made without it at all) — this provider still functions with no
 * config, just against that small shared quota, and an admin-supplied key
 * (metadata_plugins.config, briefing 15.) raises it via the documented
 * `key` query parameter.
 *
 * testConfig() (GitHub issue #164) has no separate "just check the key"
 * endpoint to call — the Volumes API has none — so it reuses the same
 * `?q=...&key=...` shape as an ordinary lookup, just with a query
 * guaranteed to have no real match ("isbn:0000000000"). A missing volume
 * is itself a plain `200` with an empty `items` array (no error) either
 * way, so the response status alone already distinguishes "key accepted"
 * from "key rejected" without needing to inspect the body at all. The
 * issue's own text left the exact invalid-key status/reason unconfirmed
 * (guessed `keyInvalid`); a live, unauthenticated-safe check with a
 * bogus key before implementing this found a plain `400` instead, with
 * Google's standard structured error body reporting `reason:
 * "badRequest"` (an `error.details[].reason: "API_KEY_INVALID"` sits one
 * level deeper) — simpler to key off the confirmed status code than that
 * deeply-nested, more failure-prone reason string. `403` is kept as a
 * second rejection status per the issue's own reasoning (a
 * valid-but-unauthorized key, e.g. restricted to a different API/
 * referrer) — not itself live-confirmed the way `400` now is.
 */
class GoogleBooksProvider implements MetadataProviderInterface, TestableMetadataProvider
{
    private const BASE_URL = 'https://www.googleapis.com/books/v1/volumes';

    public function key(): string
    {
        return 'book.google_books';
    }

    public function name(): string
    {
        return 'Google Books';
    }

    public function mediaType(): string
    {
        return 'book';
    }

    public function configFields(): array
    {
        return [
            new MetadataProviderConfigField('api_key', type: 'password', required: false),
        ];
    }

    /** See MetadataProviderInterface::version()'s docblock (GitHub issue #44). */
    public function version(): string
    {
        return 'v1.0';
    }

    /** See MetadataProviderInterface::sourceType()'s docblock (GitHub issue #55) — a real, documented API. */
    public function sourceType(): string
    {
        return 'api';
    }

    /** GitHub issue #158: a real, documented code-based lookup — see MetadataProviderInterface::supportsCodeLookup()'s own docblock. */
    public function supportsCodeLookup(): bool
    {
        return true;
    }

    /**
     * GitHub issue #164: like DiscogsProvider's own optional field, there's
     * nothing to test without a value — `false` without a request, not
     * "confirmed invalid", the same distinction TmdbProvider::testConfig()
     * already draws for an empty token. See this class's own docblock for
     * the live-confirmed `400` a real invalid key gets.
     */
    public function testConfig(array $config): bool
    {
        $key = $config['api_key'] ?? null;
        if (! is_string($key) || $key === '') {
            return false;
        }

        $response = Http::get(self::BASE_URL, ['q' => 'isbn:0000000000', 'key' => $key]);

        if ($response->successful()) {
            return true;
        }

        if (in_array($response->status(), [400, 403], true)) {
            return false;
        }

        throw new MetadataProviderRequestException("Google Books config test failed with status {$response->status()}.");
    }

    public function lookupByCode(string $code): array
    {
        $response = $this->request(['q' => "isbn:{$code}"]);

        // Distinguished from "no volume for this ISBN" below (a genuine
        // no-match): a failed request() (non-2xx — e.g. quota exceeded per
        // this class's own docblock, or a bad api_key) means the request
        // itself didn't succeed, reported as 'failed' rather than
        // 'no_match' by MetadataImportService::collectCandidatesByCode()
        // (GitHub issue #53). search() deliberately keeps request()'s
        // existing "return null, don't throw" behavior — only this
        // lookup-by-code path (the one #53 is about) distinguishes the two.
        if ($response === null) {
            throw new MetadataProviderRequestException('Google Books request failed.');
        }

        $item = $response->json('items.0');

        if (! $item) {
            return [];
        }

        return [$this->mapToCandidate($this->fetchFullVolume($item), $code)];
    }

    public function search(string $query): array
    {
        $response = $this->request(['q' => $query, 'maxResults' => 10]);

        if ($response === null) {
            return [];
        }

        // Deliberately not enriched via fetchFullVolume() here (unlike
        // lookupByCode()) — doing so for every one of up to 10 search hits
        // would multiply the request count against Google's already tight
        // shared quota (see this class's docblock) for a secondary/manual
        // lookup path. Same asymmetry OpenLibraryProvider uses: its extra
        // Editions-API call (issue #28) is only made from lookupByCode() too.
        return collect($response->json('items', []))
            ->map(fn (array $item) => $this->mapToCandidate($item, null))
            ->all();
    }

    /**
     * The search endpoint (`?q=...`) returns an abbreviated `volumeInfo` —
     * confirmed live (GitHub issue #20 follow-up, reported against EAN
     * 9783742310026 "Murdoku"): its response omitted `publisher` entirely,
     * even though a dedicated GET of the same volume ID
     * (.../v1/volumes/{id}) includes it. Fetches the canonical by-ID record
     * and prefers it, falling back to the original (still partially useful)
     * search-result item if that second call fails — same two-call pattern
     * as OpenLibraryProvider's Books-API + Editions-API split (issue #28).
     */
    private function fetchFullVolume(array $item): array
    {
        $id = $item['id'] ?? null;

        if ($id === null) {
            return $item;
        }

        $response = $this->request([], $id);

        return $response?->json() ?? $item;
    }

    private function request(array $query, ?string $volumeId = null): ?Response
    {
        if ($apiKey = $this->apiKey()) {
            $query['key'] = $apiKey;
        }

        $url = $volumeId === null ? self::BASE_URL : self::BASE_URL.'/'.$volumeId;
        $response = Http::get($url, $query);

        return $response->successful() ? $response : null;
    }

    /** Same runtime-configured-secret pattern as UpcMdbProvider::apiKey() — see that class's docblock. */
    private function apiKey(): ?string
    {
        $config = MetadataPlugin::query()->where('provider_key', $this->key())->first()?->config;

        return $config['api_key'] ?? null;
    }

    private function mapToCandidate(array $item, ?string $code): MetadataCandidate
    {
        $info = $item['volumeInfo'] ?? [];
        $identifiers = collect($info['industryIdentifiers'] ?? []);
        [$price, $currency] = $this->salePrice($item);

        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: (string) ($item['id'] ?? $code ?? ''),
            attributes: [
                'title' => $info['title'] ?? null,
                'authors' => implode(', ', $info['authors'] ?? []),
                // Google Books is a digital catalog, not a physical-media
                // database — it has no "physical_format" concept (paperback/
                // hardcover/...) at all, unlike OpenLibrary's Editions API, so
                // this is left unset rather than guessed from other fields.
                'genre' => implode(', ', $info['categories'] ?? []),
                // Google uses a literal 0 (not an absent key) as its "unknown
                // page count" placeholder for sparsely-catalogued records —
                // observed live for EAN 9783742310026 ("Murdoku"). No real
                // print book has zero pages, so 0 is treated as unknown too.
                'page_count' => ! empty($info['pageCount']) ? $info['pageCount'] : null,
                'language' => $info['language'] ?? null,
                'publisher' => $info['publisher'] ?? null,
                'release_date' => $info['publishedDate'] ?? null,
                'isbn13' => $identifiers->firstWhere('type', 'ISBN_13')['identifier'] ?? null,
                'isbn10' => $identifiers->firstWhere('type', 'ISBN_10')['identifier'] ?? null,
                'ean' => $code,
                // GitHub issue #58: unlike AmazonScraping's scraped text,
                // Google Books' own `saleInfo` reports an explicit
                // currencyCode alongside the amount, so both travel
                // together rather than assuming a fixed currency.
                'price' => $price,
                'currency' => $currency,
            ],
            coverUrls: $this->coverUrls($info['imageLinks'] ?? []),
        );
    }

    /**
     * `saleInfo.listPrice`/`retailPrice` (GitHub issue #58) — a sibling of
     * `volumeInfo` on the same `$item`, not nested inside it. Preferred:
     * `listPrice` (Google's own documented "cover price"/MSRP, closer to
     * what this app's `price` field means for a physical item than
     * `retailPrice`, which is Google's own discounted digital-edition
     * selling price), falling back to `retailPrice` when `listPrice` is
     * absent. Only reported when `saleability` is `'FOR_SALE'` — a book
     * with `saleability` `'NOT_FOR_SALE'`/`'FREE'` (out of print, public
     * domain, ...) can still carry a stale/zero price object that isn't a
     * real price to store.
     *
     * @return array{0: ?float, 1: ?string} [price, currency]
     */
    private function salePrice(array $item): array
    {
        $saleInfo = $item['saleInfo'] ?? [];

        if (($saleInfo['saleability'] ?? null) !== 'FOR_SALE') {
            return [null, null];
        }

        $listPrice = $saleInfo['listPrice'] ?? $saleInfo['retailPrice'] ?? null;

        if (! is_array($listPrice) || ! isset($listPrice['amount'])) {
            return [null, null];
        }

        return [(float) $listPrice['amount'], $listPrice['currencyCode'] ?? null];
    }

    /**
     * Building the array by explicitly mapping the preferred key order,
     * rather than Collection::only(['large', ..., 'smallThumbnail'])
     * (which does NOT reorder — it filters to those keys while preserving
     * whichever order the source `imageLinks` object itself used, so the
     * previous code silently put whatever Google listed first, typically
     * `smallThumbnail`, into cover_urls[0] regardless of the argument
     * order's apparent intent).
     *
     * Every real response observed live for this class (both during
     * initial implementation and the "cover is much too small" follow-up)
     * only ever had `thumbnail`/`smallThumbnail` at all — `large`/`medium`/
     * `small`/`extraLarge` are listed defensively per Google's documented
     * schema but have never actually been seen. Both observed URLs encode
     * a `zoom` query parameter that controls the returned resolution
     * (confirmed live against books.google.com/books/content: zoom=1, the
     * "thumbnail" default, is a mere 128x198px; zoom=3 reliably returns a
     * genuinely large ~575x889px cover; zoom=5 and above wrap back around
     * to the smallest size instead of erroring) — upgradeZoom() rewrites
     * it to request that larger size instead of trusting whichever
     * (small) rendition Google linked by default.
     */
    private function coverUrls(array $imageLinks): array
    {
        return collect(['extraLarge', 'large', 'medium', 'small', 'thumbnail', 'smallThumbnail'])
            ->map(fn (string $size) => $imageLinks[$size] ?? null)
            ->filter()
            ->map(fn (string $url) => $this->upgradeZoom(preg_replace('#^http://#', 'https://', $url)))
            ->values()
            ->all();
    }

    /** No-op for a URL that never had a `zoom` parameter to begin with (e.g. a genuine `large`/`extraLarge` link, if Google ever actually sends one). */
    private function upgradeZoom(string $url): string
    {
        return preg_replace('/([?&])zoom=\d+/', '$1zoom=3', $url) ?? $url;
    }
}
