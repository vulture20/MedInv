<?php

namespace App\Domain\Metadata\Providers\Book;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Jpc\JpcScraping;

/**
 * Book metadata via scraping jpc.de's search/product pages (GitHub issue
 * #131 — #130's original JPC implementation wrongly assumed JPC doesn't
 * sell books; it does). No longer Beta and enabled by default (GitHub
 * issue #145, an explicit user decision after the string of fixes
 * #133/#135/#136/#138/#140/#143/#144 made JPC reliable enough for
 * everyday use) — unlike AmazonScraping, which stays Beta/opt-in. See
 * JpcScraping's docblock for the full legal/technical/reliability
 * picture this is still built under, including exactly which parts were
 * confirmed against a real jpc.de book page and which are best-effort
 * guesses — promotion out of Beta doesn't mean every field is confirmed.
 *
 * `description` (GitHub issue #214) comes from a source the user found,
 * not this app's own research — see `JpcScraping::jpcDescription()`'s own
 * docblock; independently confirmed only for a film page, not a real book
 * one, but `#red-text` is a generic, non-media-type-specific container.
 * `format` is deliberately read from the confirmed `Einband:`
 * detail row (e.g. "Gebunden"), not the title tag's generic "(Buch)" —
 * see JpcScraping::jpcProductPage()'s own docblock.
 *
 * `genre` — a GitHub issue #143/#144 research check across five real,
 * varied book pages (literary fiction, children's fantasy, a Tolkien
 * companion book, a thriller, and a manga volume) found **no** "Genre:"
 * detail row on any of them, only on the one real film page originally
 * checked for #135 — this trait's own docblock has always listed
 * "Genre:" under the film label set, not the book one, so this isn't a
 * regression, but it does mean this field is expected to stay null for
 * essentially every real book in practice, not merely nullable/best-
 * effort like every other field here. Left in place rather than removed,
 * since a genre-tagged book category not yet sampled can't be ruled out.
 */
class JpcBookProvider implements MetadataProviderInterface
{
    use JpcScraping;

    public function key(): string
    {
        return 'book.jpc';
    }

    /** See AmazonBookProvider::name()'s docblock for why there's no "(Beta)" suffix here. */
    public function name(): string
    {
        return 'JPC';
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

        // splitTitleOnDash: true — the confirmed "{Titel} - {Autor}" book
        // title-tag convention, see JpcScraping::parseJpcTitleTag()'s own
        // docblock for why this is opt-in per media type.
        $page = $this->jpcProductPage($first['url'], splitTitleOnDash: true);

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
        $digits = $page['ean'];

        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: $this->jpcProductId($url),
            attributes: [
                'title' => $page['title'],
                'authors' => $page['byline'],
                // GitHub issue #214.
                'description' => $page['description'],
                'genre' => $page['genre'],
                'publisher' => $page['publisher'],
                'page_count' => $page['page_count'],
                'language' => $page['languages'],
                'release_date' => $page['release_date'],
                'format' => $page['binding'],
                'isbn10' => $digits !== null && strlen($digits) === 10 ? $digits : null,
                'isbn13' => $digits !== null && strlen($digits) === 13 ? $digits : null,
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
