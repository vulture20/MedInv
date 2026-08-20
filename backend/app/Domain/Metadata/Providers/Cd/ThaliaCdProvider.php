<?php

namespace App\Domain\Metadata\Providers\Cd;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Thalia\ThaliaScraping;

/**
 * CD metadata via scraping thalia.de's search/product pages (GitHub issue
 * #129, analogous to Amazon's #50) — **Beta**, see ThaliaScraping's
 * docblock for the full legal/technical/reliability picture this is built
 * under, including exactly which parts were confirmed against real
 * thalia.de pages and which are best-effort guesses.
 *
 * Deliberately doesn't attempt a track listing (GitHub issue #48's
 * `tracks` field) — same reasoning as AmazonCdProvider's own identical
 * omission: nothing this trait extracts (title tag, JSON-LD, Open Graph)
 * reliably exposes one in a stable, machine-parseable shape.
 */
class ThaliaCdProvider implements MetadataProviderInterface
{
    use ThaliaScraping;

    public function key(): string
    {
        return 'cd.thalia';
    }

    /** See AmazonBookProvider::name()'s docblock for why there's no "(Beta)" suffix here. */
    public function name(): string
    {
        return 'Thalia';
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

    public function version(): string
    {
        return 'v0.1-beta';
    }

    /** See MetadataProviderInterface::sourceType()'s docblock (GitHub issue #55) — scrapes thalia.de's pages, see ThaliaScraping's docblock. */
    public function sourceType(): string
    {
        return 'scraping';
    }

    public function lookupByCode(string $code): array
    {
        $results = $this->thaliaSearch($code);

        // See AmazonBookProvider::lookupByCode()'s matching comment (GitHub issue #53).
        if ($results === null) {
            throw new MetadataProviderRequestException('Thalia scrape request failed.');
        }

        $first = $results[0] ?? null;

        if ($first === null) {
            return [];
        }

        $page = $this->thaliaProductPage($first['url']);

        return [$page !== null
            ? $this->mapProductPageToCandidate($page, $first['url'], $code)
            : $this->mapSearchResultToCandidate($first, $code)];
    }

    public function search(string $query): array
    {
        // Stays search-result-level only — see AmazonBookProvider::search()'s matching comment.
        return array_map(
            fn (array $result) => $this->mapSearchResultToCandidate($result, null),
            $this->thaliaSearch($query) ?? [],
        );
    }

    private function mapProductPageToCandidate(array $page, string $url, string $code): MetadataCandidate
    {
        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: $this->thaliaProductId($url),
            attributes: [
                'title' => $page['title'],
                'description' => $page['description'],
                'artist' => $page['byline'],
                'medium' => $page['format'],
                'release_date' => $page['release_date'],
                // The originally-scanned code — see ThaliaBookProvider::mapProductPageToCandidate()'s matching comment.
                'ean' => $code,
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
            sourceId: $this->thaliaProductId($result['url']),
            attributes: [
                'title' => $result['title'],
                'ean' => $code,
            ],
            coverUrls: $result['thumbnail_url'] ? [$result['thumbnail_url']] : [],
        );
    }
}
