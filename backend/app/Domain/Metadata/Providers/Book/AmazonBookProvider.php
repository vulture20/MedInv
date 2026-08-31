<?php

namespace App\Domain\Metadata\Providers\Book;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderConfigField;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Amazon\AmazonScraping;

/**
 * Book metadata via scraping amazon.com's search/product pages (briefing
 * 8.2 — GitHub issue #50) — **Beta**, see AmazonScraping's docblock for
 * the full legal/technical/reliability picture this is built under, and
 * this class's own name()/version() for how that's surfaced to an admin.
 *
 * **Marketplace/country (GitHub issue #210)**: `configFields()` exposes the
 * shared `marketplace` select — see `AmazonScraping`'s own docblock for the
 * mechanics. Unlike `AmazonDvdBlurayProvider`, no book-specific German
 * product page was live-checked as part of that research (only a DVD/
 * Blu-ray page and a search page were), so none of this class's own field
 * labels (`Publisher`, `Language`, `ISBN-13`, ...) have a confirmed German
 * fallback yet — switching to `amazon.de` here works (the marketplace/
 * request-level plumbing is shared and confirmed), but per-field German
 * label matching for books specifically remains unconfirmed, not
 * guessed at speculatively.
 */
class AmazonBookProvider implements MetadataProviderInterface
{
    use AmazonScraping;

    public function key(): string
    {
        return 'book.amazon';
    }

    /**
     * No "(Beta)" suffix here (removed per explicit user request) — that's
     * already conveyed by version()'s "-beta" suffix in the plugin list's
     * own Version column, so keeping it in both places was redundant.
     * MetadataProviderRegistry's default-disabled handling is what
     * actually keeps this opt-in, independent of either label.
     */
    public function name(): string
    {
        return 'Amazon';
    }

    public function mediaType(): string
    {
        return 'book';
    }

    /** GitHub issue #210 — the only config: which Amazon marketplace/country to scrape. See AmazonScraping's docblock. */
    public function configFields(): array
    {
        return [
            new MetadataProviderConfigField('marketplace', type: 'select', options: ['amazon.com', 'amazon.de']),
        ];
    }

    /**
     * See MetadataProviderInterface::version()'s docblock — "-beta" is
     * exactly the free-form-string escape hatch it was written to allow.
     * Bumped from v0.1-beta to v0.2-beta (same "bump on a real, verified
     * fix" precedent TmdbProvider::version()'s own docblock already
     * establishes) for GitHub issue #137's one-time live re-check: it
     * fixed AmazonScraping::amazonPriceAndCurrency() (shared by all three
     * Amazon providers — the "always USD" assumption was wrong) and, book-
     * specifically, discovered `#bookDescription_feature_div` as the real
     * source for `description`, which had silently always been null before.
     * Bumped again to v0.3-beta for #210's marketplace selector plus #211's
     * shared price-extraction fix (both live-verified, see AmazonScraping's
     * own docblock) — this class's own field labels stay unconfirmed for
     * amazon.de specifically, see this class's own docblock.
     */
    public function version(): string
    {
        return 'v0.3-beta';
    }

    /** See MetadataProviderInterface::sourceType()'s docblock (GitHub issue #55) — scrapes amazon.com's pages, see AmazonScraping's docblock. */
    public function sourceType(): string
    {
        return 'scraping';
    }

    /** GitHub issue #158: a real, documented code-based lookup — see MetadataProviderInterface::supportsCodeLookup()'s own docblock. */
    public function supportsCodeLookup(): bool
    {
        return true;
    }

    public function lookupByCode(string $code): array
    {
        $results = $this->amazonSearch($code);

        // Distinguished from "search succeeded but found nothing" below (a
        // genuine no-match): null means the request itself didn't succeed
        // (network failure, non-2xx, a block — see AmazonScraping's own
        // docblock), reported as 'failed' rather than 'no_match' (GitHub
        // issue #53's own motivating example for this class specifically).
        if ($results === null) {
            throw new MetadataProviderRequestException('Amazon scrape request failed.');
        }

        $first = $results[0] ?? null;

        if ($first === null) {
            return [];
        }

        $page = $this->amazonProductPage($first['asin']);

        return [$page !== null
            ? $this->mapProductPageToCandidate($page, $first['asin'], $code)
            : $this->mapSearchResultToCandidate($first, $code)];
    }

    public function search(string $query): array
    {
        // Stays search-result-level only (no per-result product-page
        // fetch) — same "search stays cheap" precedent as
        // DiscogsProvider::search()/MusicBrainzProvider::search(), doubly
        // important here given how much more sensitive Amazon is to
        // request volume than either of those.
        return array_map(
            fn (array $result) => $this->mapSearchResultToCandidate($result, null),
            $this->amazonSearch($query) ?? [],
        );
    }

    private function mapProductPageToCandidate(array $page, string $asin, string $code): MetadataCandidate
    {
        $bullets = $page['bullets'];
        $publisherBullet = $this->amazonBullet($bullets, 'Publisher');

        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: $asin,
            attributes: [
                'title' => $page['title'],
                'description' => $page['description'],
                'authors' => $page['byline'],
                'publisher' => $this->stripPublisherSuffix($publisherBullet),
                'language' => $this->amazonBullet($bullets, 'Language'),
                'page_count' => $this->parseLeadingInt($this->amazonBullet($bullets, 'Print length', 'Print Length')),
                'release_date' => $this->parseAmazonDate($publisherBullet) ?? $this->parseAmazonDate($this->amazonBullet($bullets, 'Publication date', 'Publication Date')),
                'isbn10' => $this->amazonBullet($bullets, 'ISBN-10', 'ISBN10'),
                'isbn13' => $this->amazonBullet($bullets, 'ISBN-13', 'ISBN13'),
                'ean' => $code,
                // GitHub issue #58 — see AmazonScraping::amazonProductPage()'s docblock.
                'price' => $page['price'],
                'currency' => $page['currency'],
            ],
            coverUrls: $page['cover_url'] ? [$page['cover_url']] : [],
        );
    }

    /** Fallback shape when the full product-page fetch failed/was blocked — search()'s own result rows never have more than this either way. */
    private function mapSearchResultToCandidate(array $result, ?string $code): MetadataCandidate
    {
        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: $result['asin'],
            attributes: [
                'title' => $result['title'],
                'ean' => $code,
            ],
            coverUrls: $result['thumbnail_url'] ? [$result['thumbnail_url']] : [],
        );
    }
}
