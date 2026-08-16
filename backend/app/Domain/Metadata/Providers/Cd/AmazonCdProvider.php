<?php

namespace App\Domain\Metadata\Providers\Cd;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Amazon\AmazonScraping;

/**
 * CD metadata via scraping amazon.com's search/product pages (briefing
 * 8.2 — GitHub issue #50) — **Beta**, see AmazonScraping's docblock for
 * the full legal/technical/reliability picture this is built under.
 *
 * Deliberately doesn't attempt a track listing (GitHub issue #48's
 * `tracks` field, which DiscogsProvider/MusicBrainzProvider populate from
 * their structured APIs): Amazon's CD product pages don't reliably expose
 * one in a stable, machine-parseable shape the way an actual music
 * database's API does, and getting it wrong (missing/misordered tracks)
 * would be worse than not attempting it at all.
 */
class AmazonCdProvider implements MetadataProviderInterface
{
    use AmazonScraping;

    public function key(): string
    {
        return 'cd.amazon';
    }

    /** See AmazonBookProvider::name()'s docblock for why "(Beta)" lives here too, not just in version(). */
    public function name(): string
    {
        return 'Amazon (Beta)';
    }

    public function mediaType(): string
    {
        return 'cd';
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

    /** See MetadataProviderInterface::sourceType()'s docblock (GitHub issue #55) — scrapes amazon.com's pages, see AmazonScraping's docblock. */
    public function sourceType(): string
    {
        return 'scraping';
    }

    public function lookupByCode(string $code): array
    {
        $results = $this->amazonSearch($code);

        // See AmazonBookProvider::lookupByCode()'s matching comment (GitHub issue #53).
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
        // Stays search-result-level only — see AmazonBookProvider::search()'s matching comment.
        return array_map(
            fn (array $result) => $this->mapSearchResultToCandidate($result, null),
            $this->amazonSearch($query) ?? [],
        );
    }

    private function mapProductPageToCandidate(array $page, string $asin, string $code): MetadataCandidate
    {
        $bullets = $page['bullets'];

        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: $asin,
            attributes: [
                'title' => $page['title'],
                'description' => $page['description'],
                'artist' => $page['byline'],
                'asin' => $asin,
                'medium' => $this->amazonBullet($bullets, 'Format'),
                'release_date' => $this->parseAmazonDate($this->amazonBullet($bullets, 'Release Date', 'Original Release Date')),
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
                'asin' => $result['asin'],
                'ean' => $code,
            ],
            coverUrls: $result['thumbnail_url'] ? [$result['thumbnail_url']] : [],
        );
    }
}
