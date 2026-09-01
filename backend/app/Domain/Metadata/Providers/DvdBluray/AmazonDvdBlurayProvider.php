<?php

namespace App\Domain\Metadata\Providers\DvdBluray;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderConfigField;
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
 * GitHub issue #219, a further user report: the `Actors` bullet's
 * confirmed-good "{First} {Last}, ..." shape above isn't universal — a
 * live, authorized check (ASIN B0CP79YKSD, "SAW 1-9 - Gesamtedition
 * [Blu-ray]") found the exact same bullet, on a different real product,
 * flattened to "{Last}, {First}" repeated with no structural way to tell
 * an intra-name comma from an inter-actor one apart (e.g. "Bell, Tobin,
 * Elwes, Cary, ..." for "Tobin Bell, Cary Elwes, ..."). `cast` is now
 * additionally passed through `AmazonScraping::
 * recombineSplitAmazonCastNames()`, a text-only heuristic that only acts
 * when every comma-segment is a single bare word (never true for the
 * already-correct shape above, where each segment already contains a
 * space) — see that method's own docblock for the full reasoning.
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
 *
 * **Marketplace/country (GitHub issue #210)**: `configFields()` exposes the
 * `marketplace` select — see `AmazonScraping`'s own docblock for the shared
 * mechanics. That same research live-checked a real amazon.de page (Ant-Man
 * Blu-ray, B07447J2TS again) and confirmed two further, purely additive
 * German fallback labels for `languages`: `Sprache` (a `#productOverview_
 * feature_div` row, e.g. "Sprache" → "Englisch") and `Synchronisiert:` (a
 * `#detailBullets_feature_div` bullet, same value on the same page) — both
 * describe the audio language, exactly what this field already represents.
 * `medium` ("Format:") and `subtitles` ("Untertitel:") were re-confirmed
 * unchanged against that same real page. No structured `director`/`genre`/
 * `cast` bullet was present on that particular page at all (checked
 * `#bylineInfo`, `#detailBullets_feature_div`, `#productOverview_feature_div`
 * — all three came up empty for those fields, "Peyton Reed"/"Paul Rudd" only
 * ever appearing inside free-text customer reviews) — not itself evidence
 * those fields are broken on amazon.de, just unconfirmed by this one page,
 * so no new German label is added for them.
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
     * establishes) for the accumulated, live-verified fixes since v0.1-beta:
     * #137's shared amazonPriceAndCurrency() fix, #139's "Format: DVD"
     * cast-contamination stripping, #140/#141's genre/subtitles extraction,
     * and #150/#173's cast field being removed and then reintroduced on a
     * confirmed English "Actors" bullet — see this class's own docblock and
     * AmazonScraping's for the full history. Bumped again to v0.3-beta for
     * #210's marketplace selector plus #211's price-extraction fix (both
     * live-verified against a real amazon.de page, see this class's and
     * AmazonScraping's own docblocks).
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
                // 'Sprache'/'Synchronisiert' (GitHub issue #210) are the
                // confirmed German fallbacks — see this class's own
                // docblock for the real page that confirmed both.
                // 'Synchronisiert' has no trailing colon here despite the
                // real page's own label literally reading "Synchronisiert:"
                // (RLM) ":" (LRM) — amazonDetailBullets() already rtrims
                // every trailing ASCII colon/space when storing a bullet
                // key, so the double colon collapses away entirely by the
                // time this lookup runs; a 'Synchronisiert:' entry here
                // would never match.
                'languages' => $this->amazonBullet($bullets, 'Language', 'Language:', 'Languages', 'Sprache', 'Synchronisiert'),
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
                // GitHub issue #219: recombineSplitAmazonCastNames() undoes
                // a confirmed "{Last}, {First}" flattening some pages use
                // for this same bullet — see its own docblock.
                'cast' => $this->recombineSplitAmazonCastNames($this->stripAmazonFormatContamination(
                    $this->amazonBullet($bullets, 'Actors', 'Actors:', 'Darsteller', 'Darsteller:')
                )),
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
