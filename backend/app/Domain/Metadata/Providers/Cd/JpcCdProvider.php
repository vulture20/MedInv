<?php

namespace App\Domain\Metadata\Providers\Cd;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Jpc\JpcScraping;

/**
 * CD metadata via scraping jpc.de's search/product pages (GitHub issue
 * #130, analogous to Amazon's #50) — **Beta**, see JpcScraping's docblock
 * for the full legal/technical/reliability picture this is built under,
 * including exactly which parts were confirmed against real jpc.de pages
 * and which are best-effort guesses.
 *
 * Deliberately doesn't attempt a track listing (GitHub issue #48's
 * `tracks` field) — same reasoning as AmazonCdProvider's/ThaliaCdProvider's
 * own identical omission: nothing this trait extracts reliably exposes
 * one in a stable, machine-parseable shape.
 */
class JpcCdProvider implements MetadataProviderInterface
{
    use JpcScraping;

    public function key(): string
    {
        return 'cd.jpc';
    }

    /** See AmazonBookProvider::name()'s docblock for why there's no "(Beta)" suffix here. */
    public function name(): string
    {
        return 'JPC';
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
                'artist' => $page['byline'],
                'medium' => $page['format'],
                'release_date' => $page['release_date'],
                // The originally-scanned code, same as
                // AmazonBookProvider::mapProductPageToCandidate()'s own
                // 'ean' field — not the page-derived one, which may be
                // formatted differently or simply absent.
                'ean' => $code,
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
