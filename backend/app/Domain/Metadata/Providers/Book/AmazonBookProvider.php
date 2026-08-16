<?php

namespace App\Domain\Metadata\Providers\Book;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\Providers\Amazon\AmazonScraping;

/**
 * Book metadata via scraping amazon.com's search/product pages (briefing
 * 8.2 — GitHub issue #50) — **Beta**, see AmazonScraping's docblock for
 * the full legal/technical/reliability picture this is built under, and
 * this class's own name()/version() for how that's surfaced to an admin.
 */
class AmazonBookProvider implements MetadataProviderInterface
{
    use AmazonScraping;

    public function key(): string
    {
        return 'book.amazon';
    }

    /** "(Beta)" suffix kept in the name itself, not just version() — visible in the plugin list's Name column without an admin needing to notice a separate Version column (see MetadataProviderRegistry's default-disabled handling for the other half of making this opt-in, not just visible). */
    public function name(): string
    {
        return 'Amazon (Beta)';
    }

    public function mediaType(): string
    {
        return 'book';
    }

    /** No API key — there is no API. */
    public function configFields(): array
    {
        return [];
    }

    /** See MetadataProviderInterface::version()'s docblock — "-beta" is exactly the free-form-string escape hatch it was written to allow. */
    public function version(): string
    {
        return 'v0.1-beta';
    }

    public function lookupByCode(string $code): array
    {
        $results = $this->amazonSearch($code);
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
            $this->amazonSearch($query),
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
