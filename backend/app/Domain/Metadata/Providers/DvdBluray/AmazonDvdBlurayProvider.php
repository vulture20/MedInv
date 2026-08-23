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
 * `cast` (GitHub issue #173, reintroduced after being removed entirely by
 * #150) is sourced from the `Actors` bullet in `detailBullets_feature_div`
 * — confirmed live via a real English "clp" product page the user
 * provided directly (Ant-Man [4K UHD], B07TPYXN5C): a clean, plain
 * comma-separated actor list ("Bobby Cannavale, Corey Stoll, Evangeline
 * Lilly, Michael Peña, Paul Rudd"), no role annotations, no stray "Format:"
 * text — the exact English spelling ("Actors") this trait's docblock had
 * only ever guessed at before now. `Darsteller`/`Darsteller:` stays as the
 * purely additive German fallback GitHub issue #141 already confirmed on
 * an earlier real dump (same container, same field). Still passed through
 * `AmazonScraping::stripAmazonFormatContamination()` as defensive
 * hardening (GitHub issue #139) even though this particular confirmed page
 * showed no such contamination — that finding was never disproven, only
 * not re-observed here, so the belt-and-suspenders stripping stays.
 *
 * Unlike the pre-#150 version of this field, there is deliberately no
 * `?? $page['byline']` fallback for a page with no `Actors` bullet at all:
 * that same confirmed dump's own `#bylineInfo` turned out to mix actors
 * *and* crew in one run-on string (e.g. "Paul Rudd (Actor, Writer), ...
 * Peyton Reed (Director) ...", with per-person role annotations `cast`
 * has no business carrying) — very plausibly the real, previously
 * undiagnosed cause behind #150's "wrong in general" report, not the
 * "Format: DVD" contamination #139 had already fixed. A page with no
 * `Actors`/`Darsteller` bullet now just leaves `cast` unset (`null`, same
 * as `genre`/`director`/`subtitles` when their own bullet is absent)
 * rather than risking that same mixed-roles data again.
 *
 * `genre`/`subtitles` (GitHub issue #140) were originally best-effort
 * `amazonBullet()` label guesses ("Genre"/"Genres", "Subtitles") — GitHub
 * issue #141 tried three further live re-checks, all blocked by Amazon's
 * bot detection (two HTTP 500s, one 503) the same way #139's own attempts
 * were, and a fourth attempt (a direct `curl` fetch bypassing whatever
 * tool produced the earlier 500s) still hit the same generic bot-block
 * page. `genre` was then confirmed by a real DVD/Blu-ray product page the
 * user provided directly (a "clp" page for "Ant-Man", B07447J2TS) —
 * "Genre" does exist, but in a container this trait never looked at
 * before (`#productOverview_feature_div`, not `detailBullets_feature_div`
 * — see `AmazonScraping::amazonDetailBullets()`'s own docblock for the
 * fix). `subtitles` itself is still not confirmed in English on that
 * page (no `Subtitles`/`Subtitles:` bullet was present at all — only the
 * German label was), so its English guess remains exactly that; the
 * confirmed German "Untertitel"/"Untertitel:" label was added as a purely
 * additive fallback (see `mapProductPageToCandidate()`), not a
 * replacement for the English guess.
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
        $releaseDate = $this->parseAmazonDate($this->amazonBullet($bullets, 'Release Date', 'DVD Release Date', 'Blu-ray Release Date'));

        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: $asin,
            attributes: [
                'title' => $page['title'],
                'description' => $page['description'],
                // 'Medienformat' (GitHub issue #141) is the German label
                // confirmed alongside 'Genre' below on the same real page
                // — see AmazonScraping::amazonDetailBullets()'s docblock.
                'medium' => $this->amazonBullet($bullets, 'Format', 'Medienformat'),
                'runtime_minutes' => $this->parseLeadingInt($this->amazonBullet($bullets, 'Run time', 'Runtime')),
                'languages' => $this->amazonBullet($bullets, 'Language', 'Language:', 'Languages'),
                'director' => $this->amazonBullet($bullets, 'Director', 'Directors'),
                // GitHub issue #141 confirmed 'Genre' against a real page
                // (see AmazonScraping::amazonDetailBullets()'s docblock for
                // where it actually lives) — no longer an unconfirmed
                // guess. 'Subtitles' itself is still unconfirmed in
                // English; 'Untertitel'/'Untertitel:' is the real German
                // label found on that same page, added as an additional,
                // purely additive fallback (never matches an English page,
                // so this can't regress anything already working).
                'genre' => $this->amazonBullet($bullets, 'Genre', 'Genres'),
                'subtitles' => $this->amazonBullet($bullets, 'Subtitles', 'Subtitles:', 'Untertitel', 'Untertitel:'),
                // GitHub issue #173 — see this class's own docblock for
                // the real page that confirmed 'Actors' and for why there
                // is deliberately no `?? $page['byline']` fallback here.
                'cast' => $this->stripAmazonFormatContamination(
                    $this->amazonBullet($bullets, 'Actors', 'Actors:', 'Darsteller', 'Darsteller:')
                ),
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
