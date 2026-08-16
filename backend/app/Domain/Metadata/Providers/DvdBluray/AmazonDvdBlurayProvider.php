<?php

namespace App\Domain\Metadata\Providers\DvdBluray;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Amazon\AmazonScraping;

/**
 * DVD/Blu-ray metadata via scraping amazon.com's search/product pages
 * (briefing 8.2 — GitHub issue #50) — **Beta**, see AmazonScraping's
 * docblock for the full legal/technical/reliability picture this is
 * built under.
 */
class AmazonDvdBlurayProvider implements MetadataProviderInterface
{
    use AmazonScraping;

    public function key(): string
    {
        return 'dvd_bluray.amazon';
    }

    /** See AmazonBookProvider::name()'s docblock for why "(Beta)" lives here too, not just in version(). */
    public function name(): string
    {
        return 'Amazon (Beta)';
    }

    public function mediaType(): string
    {
        return 'dvd_bluray';
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
        $releaseDate = $this->parseAmazonDate($this->amazonBullet($bullets, 'Release Date', 'DVD Release Date', 'Blu-ray Release Date'));

        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: $asin,
            attributes: [
                'title' => $page['title'],
                'description' => $page['description'],
                'medium' => $this->amazonBullet($bullets, 'Format'),
                'runtime_minutes' => $this->parseLeadingInt($this->amazonBullet($bullets, 'Run time', 'Runtime')),
                'languages' => $this->amazonBullet($bullets, 'Language', 'Language:', 'Languages'),
                'cast' => $this->amazonBullet($bullets, 'Actors') ?? $page['byline'],
                'director' => $this->amazonBullet($bullets, 'Director', 'Directors'),
                'release_date' => $releaseDate,
                'production_year' => $releaseDate ? (int) substr($releaseDate, 0, 4) : null,
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
