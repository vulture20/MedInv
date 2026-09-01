<?php

namespace App\Domain\Metadata\Providers\DvdBluray;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Jpc\JpcScraping;

/**
 * DVD/Blu-ray metadata via scraping jpc.de's search/product pages (GitHub
 * issue #130, analogous to Amazon's #50). No longer Beta and enabled by
 * default (GitHub issue #145 — see JpcBookProvider's own docblock for
 * the full reasoning) — unlike AmazonDvdBlurayProvider, which stays
 * Beta/opt-in. See JpcScraping's docblock for the full legal/technical/
 * reliability picture this is still built under, including exactly which
 * parts were confirmed against real jpc.de pages and which are
 * best-effort guesses. `cast` (GitHub issue #213) comes from a confirmed
 * real `Darsteller:` detail-row label, found on a further live check
 * after the original research missed it entirely — see JpcScraping's own
 * docblock for the full story.
 *
 * `disc_count` (GitHub issue #136) is parsed out of the *original*
 * title-tag format string (e.g. "2 DVDs") rather than from any dedicated
 * label — jpc.de has none — see `JpcScraping::jpcProductPage()`'s own
 * docblock for why. `medium` itself no longer repeats that count
 * (GitHub issue #138: "2 DVDs" → "DVD") — see `stripJpcDiscCount()`'s
 * own docblock.
 *
 * `genre`/`subtitles` (GitHub issue #140) come from confirmed real
 * `Genre:`/`Untertitel:` detail-row labels — `genre` was already
 * extracted by `JpcScraping::jpcProductPage()` (used by
 * `JpcBookProvider` since MediaBook was the only model with a `genre`
 * column at the time), just never mapped here until `MediaDvdBluray`
 * gained one too.
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

    /** GitHub issue #145: no longer Beta — see this class's own docblock. */
    public function version(): string
    {
        return 'v1.0';
    }

    /** See MetadataProviderInterface::sourceType()'s docblock (GitHub issue #55) — scrapes jpc.de's pages, see JpcScraping's docblock. */
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
                // GitHub issue #140.
                'subtitles' => $page['subtitles'],
                'director' => $page['director'] ?? $page['byline'],
                // GitHub issue #213.
                'cast' => $page['cast'],
                'genre' => $page['genre'],
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
