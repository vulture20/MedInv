<?php

namespace App\Domain\Metadata\Providers\Cd;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderConfigField;
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
 *
 * **Marketplace/country (GitHub issue #210)**: `configFields()` exposes the
 * shared `marketplace` select — see `AmazonScraping`'s own docblock for the
 * mechanics. As with `AmazonBookProvider`, no CD-specific German product
 * page was live-checked as part of that research, so this class's own field
 * labels (`Format`, `Release Date`) have no confirmed German fallback yet —
 * only the shared marketplace/request-level plumbing is confirmed working.
 */
class AmazonCdProvider implements MetadataProviderInterface
{
    use AmazonScraping;

    public function key(): string
    {
        return 'cd.amazon';
    }

    /** See AmazonBookProvider::name()'s docblock for why there's no "(Beta)" suffix here. */
    public function name(): string
    {
        return 'Amazon';
    }

    public function mediaType(): string
    {
        return 'cd';
    }

    /** GitHub issue #210 — the only config: which Amazon marketplace/country to scrape. See AmazonScraping's docblock. */
    public function configFields(): array
    {
        return [
            new MetadataProviderConfigField('marketplace', type: 'select', options: self::MARKETPLACES),
        ];
    }

    /**
     * See MetadataProviderInterface::version()'s docblock — "-beta" is
     * exactly the free-form-string escape hatch it was written to allow.
     * Bumped from v0.1-beta to v0.2-beta (same "bump on a real, verified
     * fix" precedent TmdbProvider::version()'s own docblock already
     * establishes) for GitHub issue #137's one-time live re-check, which
     * fixed AmazonScraping::amazonPriceAndCurrency() (shared by all three
     * Amazon providers) — the standing "hardcoded amazon.com always means
     * USD" assumption was wrong, a real page checked showed EUR. Bumped
     * again to v0.3-beta for #210's marketplace selector plus #211's shared
     * price-extraction fix (both live-verified, see AmazonScraping's own
     * docblock) — this class's own field labels stay unconfirmed for
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
