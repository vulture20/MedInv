<?php

namespace App\Domain\Metadata\Providers\DvdBluray;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Jpc\JpcScraping;

/**
 * DVD/Blu-ray metadata via scraping jpc.de's search/product pages (GitHub
 * issue #130, analogous to Amazon's #50) — **Beta**, see JpcScraping's
 * docblock for the full legal/technical/reliability picture this is built
 * under, including exactly which parts were confirmed against real
 * jpc.de pages and which are best-effort guesses. `cast` is deliberately
 * never set — see JpcScraping's own docblock for why.
 *
 * `disc_count` (GitHub issue #136) is parsed out of the same title-tag
 * format string `medium` already comes from (e.g. "2 DVDs") rather than
 * from any dedicated label — jpc.de has none — see
 * `JpcScraping::jpcProductPage()`'s own docblock for why.
 */
class JpcDvdBlurayProvider implements MetadataProviderInterface
{
    use JpcScraping;

    public function key(): string
    {
        return 'dvd_bluray.jpc';
    }

    /** See AmazonBookProvider::name()'s docblock for why there's no "(Beta)" suffix here. */
    public function name(): string
    {
        return 'JPC';
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

    public function version(): string
    {
        return 'v0.1-beta';
    }

    /** See MetadataProviderInterface::sourceType()'s docblock (GitHub issue #55) — scrapes jpc.de's pages, see JpcScraping's docblock. */
    public function sourceType(): string
    {
        return 'scraping';
    }

    public function lookupByCode(string $code): array
    {
        $results = $this->jpcSearch($code);

        // See AmazonBookProvider::lookupByCode()'s matching comment (GitHub issue #53).
        if ($results === null) {
            throw new MetadataProviderRequestException('JPC scrape request failed.');
        }

        $first = $results[0] ?? null;

        if ($first === null) {
            return [];
        }

        $page = $this->jpcProductPage($first['url']);

        return [$page !== null
            ? $this->mapProductPageToCandidate($page, $first['url'], $code)
            : $this->mapSearchResultToCandidate($first, $code)];
    }

    public function search(string $query): array
    {
        // Stays search-result-level only — see AmazonBookProvider::search()'s matching comment.
        return array_map(
            fn (array $result) => $this->mapSearchResultToCandidate($result, null),
            $this->jpcSearch($query) ?? [],
        );
    }

    private function mapProductPageToCandidate(array $page, string $url, string $code): MetadataCandidate
    {
        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: $this->jpcProductId($url),
            attributes: [
                'title' => $page['title'],
                'medium' => $page['format'],
                'disc_count' => $page['disc_count'],
                'runtime_minutes' => $page['runtime_minutes'],
                'languages' => $page['languages'],
                'director' => $page['director'] ?? $page['byline'],
                'release_date' => $page['release_date'],
                'production_year' => $page['release_date'] ? (int) substr($page['release_date'], 0, 4) : null,
                // The originally-scanned code — see JpcCdProvider::mapProductPageToCandidate()'s matching comment.
                'ean' => $code,
                'price' => $page['price'],
                'currency' => $page['currency'],
            ],
            coverUrls: ($cover = $this->jpcCoverUrl($page['ean'])) ? [$cover] : [],
        );
    }

    /** Fallback shape when the full product-page fetch failed/was blocked — search()'s own result rows never have more than this either way. */
    private function mapSearchResultToCandidate(array $result, ?string $code): MetadataCandidate
    {
        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: $this->jpcProductId($result['url']),
            attributes: [
                'title' => $result['title'],
                'ean' => $code,
            ],
            coverUrls: $result['thumbnail_url'] ? [$result['thumbnail_url']] : [],
        );
    }
}
