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
 *
 * `cast` (GitHub issue #139: a user reported "Format: DVD" landing here)
 * is passed through `AmazonScraping::stripAmazonFormatContamination()` —
 * see that method's own docblock for why this specific case couldn't be
 * confirmed live the way #137's book-side fix was.
 *
 * `genre`/`subtitles` (GitHub issue #140) are best-effort `amazonBullet()`
 * label guesses ("Genre"/"Genres", "Subtitles") — unlike JPC's equivalent
 * fields, neither label was ever seen on a real Amazon page (the one live
 * check this provider ever got, #137, was a book page, and #139's two DVD
 * re-check attempts were both blocked by Amazon's bot detection before
 * any markup could be inspected), so treat these two specifically as
 * unconfirmed even by this provider's own already-cautious standards.
 */
class AmazonDvdBlurayProvider implements MetadataProviderInterface
{
    use AmazonScraping;

    public function key(): string
    {
        return 'dvd_bluray.amazon';
    }

    /** See AmazonBookProvider::name()'s docblock for why there's no "(Beta)" suffix here. */
    public function name(): string
    {
        return 'Amazon';
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
                // GitHub issue #139: stripAmazonFormatContamination() —
                // $page['byline'] is already stripped by amazonProductPage()
                // itself, but the "Actors" bullet value isn't, so it needs
                // the same defensive treatment here too.
                'cast' => $this->stripAmazonFormatContamination($this->amazonBullet($bullets, 'Actors')) ?? $page['byline'],
                'director' => $this->amazonBullet($bullets, 'Director', 'Directors'),
                // GitHub issue #140: unconfirmed guesses — neither label
                // was seen on the one real Amazon page ever checked
                // (#137's book page, a different category), so this is a
                // plausible-label attempt rather than a confirmed
                // extraction, same restraint as every other Amazon field
                // that's never been individually re-verified.
                'genre' => $this->amazonBullet($bullets, 'Genre', 'Genres'),
                'subtitles' => $this->amazonBullet($bullets, 'Subtitles', 'Subtitles:'),
                'release_date' => $releaseDate,
                'production_year' => $releaseDate ? (int) substr($releaseDate, 0, 4) : null,
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
