<?php

namespace App\Domain\Metadata\Providers\Jpc;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared HTTP + HTML parsing for the three JPC providers (JpcBook/JpcCd/
 * JpcDvdBlurayProvider — GitHub issue #130, extended to books by GitHub
 * issue #131 after #130 itself wrongly assumed JPC doesn't sell books —
 * it does). Structural sibling of AmazonScraping (#50) and — historically,
 * see below — ThaliaScraping (#129, GitHub issue #134: removed again once
 * confirmed permanently blocked by thalia.de's Cloudflare bot-management,
 * a fate JPC didn't share; the comparisons to it throughout this docblock
 * are kept as accurate history of how this class was built, even though
 * that sibling class no longer exists in the codebase to link to): jpc.de
 * has no public lookup API either, so this scrapes the same product/
 * search pages a browser would load — **explicitly a Beta feature**,
 * disabled by default, for the same legal/technical/maintenance reasons
 * AmazonScraping's own docblock lays out in full (not repeated here).
 * Like Thalia was, jpc.de isn't a briefing-listed source — added purely
 * because it was explicitly requested.
 *
 * ## What's confirmed vs. guessed here — read this before trusting any of it
 *
 * jpc.de does **not** block a direct fetch — homepage, robots.txt, and
 * product pages all load normally over a plain HTTP GET, confirmed with
 * both this app's own `Http::` client and a standalone `curl` request.
 * That said, GitHub issue #135 is the reason this docblock exists in its
 * current, much more confident form: the WebFetch-style research tool
 * used for the *original* #130/#131 implementation converts a page to
 * Markdown before answering questions about it, which turned out to
 * silently misrepresent DOM structure it was asked to quote "verbatim"
 * (plausible-looking but partly reconstructed HTML, not the real byte
 * source) — the root cause of a real bug reported in production (see
 * `jpcDetailValue()`'s own docblock). #135 re-verified everything below
 * against real, byte-exact HTML fetched directly (`curl`, no Markdown
 * conversion in between) across three real, distinct product pages (one
 * CD, one Blu-ray/DVD, one book), and confirmed meaningfully more as a
 * result — including that jpc.de embeds real schema.org **Microdata**
 * (`itemscope`/`itemtype`/`itemprop` attributes), not JSON-LD, which
 * several fields below now read from directly rather than guessing at
 * label text alone:
 *  - **Product-detail URLs** follow `/jpcng/{category}/detail/-/art/
 *    {slug}/hnum/{id}` (category e.g. `poprock`, `movie`, `books`, `jazz`,
 *    `classic`, `vinyl` — irrelevant to parsing here, since a found
 *    product's own URL is used as-is, never reconstructed).
 *  - **The `<title>` tag** follows one of two confirmed shapes, both
 *    always ending `({Format}) – jpc.de`: `{Artist}: {Titel} ({Format})
 *    – jpc.de` for a CD (e.g. "Mark Medlock (DSDS): Back Into The Sun
 *    (CD) – jpc.de", byline *before* the title, colon-separated) and
 *    `{Titel} - {Autor} ({Format}) – jpc.de` for a book (e.g. "Kummer
 *    aller Art - Mariana Leky (Buch) – jpc.de", byline *after* the title,
 *    hyphen-separated — the reverse order and a different separator from
 *    the CD shape) — a film has no byline segment at all (e.g. "El Topo
 *    (Blu-ray) – jpc.de"). parseJpcTitleTag() below handles the
 *    parenthesized-format part generically for all three, but the two
 *    *conflicting* byline conventions are deliberately not both applied
 *    unconditionally: colon-splitting is always tried (safe — no
 *    confirmed film/book title contains ": "), but hyphen-splitting is
 *    opt-in per caller (only JpcBookProvider passes `splitTitleOnDash:
 *    true`), since a film title can legitimately contain its own " - "
 *    subtitle separator (e.g. a fictional "El Topo - Director's Cut")
 *    that JpcDvdBlurayProvider must not misread as a byline.
 *  - **The cover image URL is directly derivable from the EAN/ISBN-13**:
 *    `https://media1.jpc.de/image/w2400/front/0/{ean-or-isbn13}.jpg` —
 *    re-confirmed on all three real pages checked for #135, this time
 *    against the actual `<img src>`/enclosing `<a href>` pair rather than
 *    a single earlier read, which also surfaced that `w2400` (not the
 *    `w468` originally used) is the real, full-resolution version linked
 *    from the same image, consistently across all three media types —
 *    unlike Amazon/Thalia, this needs no `<img>`/`og:image` extraction at
 *    all once a code is known.
 *  - **`price`/`currency`** — reversing #130's own original decision not
 *    to extract these at all (see `jpcPrice()`'s own docblock for the
 *    full story): real, confirmed `<meta itemprop="price" content="...">`
 *    / `<meta itemprop="priceCurrency" content="...">` pairs inside a
 *    `schema.org/Offer`-typed block, present on all three real pages
 *    checked for #135.
 *  - **A CD's track listing** — also reversing an original decision not
 *    to extract it (see `jpcTracks()`'s own docblock): real
 *    `schema.org/MusicRecording` microdata items, each carrying a track
 *    title (`itemprop="name"`) and a plain visible position number, no
 *    duration.
 *  - **The search endpoint**: `/jpcng/home/search?fastsearch={query}` —
 *    confirmed live for GitHub issue #133 after the original guess
 *    (`/jpcng/search`, a path that turned out not to exist at all) was
 *    reported as returning zero results in production. `/jpcng/home/
 *    searchform` (the page originally found) turned out to be a real,
 *    but merely adjacent, "advanced search" landing page rather than the
 *    results endpoint itself. The actual parameter name (`fastsearch`)
 *    was recovered from a real `<input type="search" name="fastsearch"
 *    ...>` element on jpc.de's own header search box, shared directly by
 *    the reporting user — every previously-tried parameter name
 *    (`searchtext`, `q`, `query`, `suchbegriff`) silently returned the
 *    same ~3.38M-item unfiltered full catalog regardless of the query
 *    value, which is what originally made this so hard to notice by
 *    trial and error alone (a wrong path/param 404s or errors somewhere
 *    else in this app's providers; here it silently "succeeded" with
 *    irrelevant results instead). `?fastsearch=queen` was confirmed to
 *    return "Ihre Suche nach 'Queen' ... ergab 46345 Treffer" with actual
 *    Queen albums, and `?fastsearch={ean}` was confirmed to resolve an
 *    exact EAN barcode straight to its matching product.
 *  - **Product detail rows** use real, confirmed German labels — for a
 *    CD: `Label:`, `Aufnahmejahr ca.:`, `Artikelnummer:`, `UPC/EAN:`,
 *    `Erscheinungstermin:`; for a film, additionally: `Herkunftsland:`,
 *    `Altersfreigabe:`, `Serie:`, `Genre:`, `Spieldauer ca.:`, `Regie:`,
 *    `Filmmusik:`, `Originaltitel:`, `Sprache:`, `Tonformat:`, `Bild:`,
 *    `Untertitel:`; for a book: `Verlag:` (publisher, but combined with a
 *    trailing `, MM/YYYY` that stripJpcPublisherSuffix() below strips
 *    off), `Einband:` (the specific binding, e.g. "Gebunden" — a much
 *    more useful book `format` value than the generic "(Buch)" the title
 *    tag itself carries, so this is preferred over it), `Sprache:`,
 *    `ISBN-13:` (no `ISBN-10:` label was observed), `Artikelnummer:`,
 *    `Umfang:` (page count, e.g. "176 Seiten"), `Erscheinungstermin:`.
 *    **The exact DOM shape around a label is now confirmed too, and it
 *    isn't consistent**: real markup mixes bare `<dt>Verlag:</dt>` with
 *    `<dt><b>UPC/EAN:</b></dt>` (label text wrapped in an inline `<b>`)
 *    on the very same page — see `jpcDetailValue()`'s own docblock for
 *    why that inconsistency was the actual root cause of GitHub issue
 *    #135's "very few attributes, no cover" report, and how it's now
 *    handled. A meta `<meta name="description" ... itemprop="description">`
 *    exists on every page type but is generic marketing boilerplate
 *    ("jetzt für X Euro kaufen"), not a real synopsis — deliberately
 *    still never used as `description`, now a *confirmed* content
 *    judgment rather than an absence-of-evidence one.
 *
 * What remains an unconfirmed guess:
 *  - **`jpcSearch()`'s results-page markup** — never actually seen (the
 *    search endpoint's own real response HTML wasn't part of #135's
 *    re-verification), so this still matches generically on any anchor
 *    whose `href` contains the one confirmed constant, `/detail/-/art/`,
 *    rather than guessing at a results-container class.
 *  - **`cast` (DVD/Blu-ray)** — no "Darsteller"/"Besetzung"-style label
 *    or microdata was observed on the one real film page checked, so,
 *    unlike AmazonDvdBlurayProvider (which has a confirmed "Actors"
 *    bullet and a byline fallback), JpcDvdBlurayProvider never sets this
 *    field at all rather than guessing at an unconfirmed label.
 *  - **`Label:` (record label)** was confirmed as a real CD detail-row
 *    label but is never extracted — neither `MediaCd` nor any other
 *    in-scope model has a fillable column it would map to.
 *
 * Every field extracted here is nullable/best-effort for the same reason
 * AmazonScraping's fields are.
 *
 * `de-DE` is requested (Accept-Language) since jpc.de is a German-market
 * site and every confirmed label above is German.
 */
trait JpcScraping
{
    private const BASE_URL = 'https://www.jpc.de';

    /**
     * GitHub issue #133: confirmed live, replacing the original unverified
     * guess (`/jpcng/search`, which never actually existed) — a real
     * `<input type="search" name="fastsearch" ...>` element on jpc.de's
     * own search box, shared by the reporting user, revealed the true
     * query parameter name; `/jpcng/home/search` was independently
     * confirmed as the real results endpoint by testing it directly
     * (`?fastsearch=queen` returns "Ihre Suche nach 'Queen' ... ergab
     * 46345 Treffer" with actual Queen albums, as opposed to every
     * previously-tried parameter name, which silently returned the same
     * ~3.38M-item unfiltered full catalog regardless of the query value —
     * also confirmed to resolve an exact EAN barcode straight to its
     * matching product).
     */
    private const SEARCH_PATH = '/jpcng/home/search';

    /** GitHub issue #133 — see SEARCH_PATH's own docblock. */
    private const SEARCH_QUERY_PARAM = 'fastsearch';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private const TIMEOUT_SECONDS = 10;

    /**
     * Parses jpc.de's search-results page for a query (a free-text search
     * or, for lookupByCode(), the scanned EAN itself). Matches generically
     * on any `/detail/-/art/`-containing anchor rather than a guessed
     * results-container shape — see this trait's own docblock for why.
     * When the same product URL appears via more than one anchor,
     * whichever anchor actually carried non-empty text wins the title,
     * same merge logic as ThaliaScraping::thaliaSearch().
     *
     * Null (not an empty array) specifically means the request itself
     * didn't succeed — same 'failed' vs. 'no_match' distinction
     * AmazonScraping::amazonSearch()/ThaliaScraping::thaliaSearch()
     * document (GitHub issue #53).
     *
     * @return array<int, array{url: string, title: ?string, thumbnail_url: ?string}>|null
     */
    private function jpcSearch(string $query): ?array
    {
        $html = $this->jpcGet(self::BASE_URL.self::SEARCH_PATH, [self::SEARCH_QUERY_PARAM => $query]);

        if ($html === null) {
            return null;
        }

        $xpath = $this->xpathFor($html);
        $results = [];
        $indexByUrl = [];

        foreach ($xpath->query('//a[contains(@href, "/detail/-/art/")]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $url = $this->absoluteJpcUrl($node->getAttribute('href'));
            if ($url === null) {
                continue;
            }

            $title = $this->cleanText($node->textContent);
            $imageNode = $xpath->query('.//img', $node)->item(0);
            $thumbnailUrl = $imageNode instanceof DOMElement
                ? ($imageNode->getAttribute('src') ?: $imageNode->getAttribute('data-src') ?: null)
                : null;

            if (isset($indexByUrl[$url])) {
                $existing = $results[$indexByUrl[$url]];
                $results[$indexByUrl[$url]] = [
                    'url' => $url,
                    'title' => $existing['title'] ?? $title,
                    'thumbnail_url' => $existing['thumbnail_url'] ?? $thumbnailUrl,
                ];

                continue;
            }

            $indexByUrl[$url] = count($results);
            $results[] = ['url' => $url, 'title' => $title, 'thumbnail_url' => $thumbnailUrl];
        }

        return $results;
    }

    /**
     * Parses one product page — see this trait's own docblock for exactly
     * which parts are confirmed (the `<title>` tag; the German detail-row
     * labels; `price`/`currency`/track-listing microdata) vs. best-effort
     * (the EAN/ISBN-derived cover URL, always attempted regardless of
     * whether the page actually has that product's image).
     *
     * @param  bool  $splitTitleOnDash  Opt into the confirmed book-only `{Titel} - {Autor}` title-tag convention — see this trait's own docblock for why this isn't applied unconditionally to every media type. Only JpcBookProvider passes true.
     *
     * `description` is deliberately never populated — see this trait's
     * own docblock. `Label:` (record label) was confirmed as a real CD
     * detail-row label too, but isn't extracted here at all: no in-scope
     * model has a fillable column it would map to — extracting a value
     * with nowhere to put it would just be dead code.
     * @return array{title: ?string, byline: ?string, format: ?string, disc_count: ?int, ean: ?string, release_date: ?string, runtime_minutes: ?int, languages: ?string, director: ?string, genre: ?string, publisher: ?string, page_count: ?int, binding: ?string, price: ?float, currency: ?string, tracks: ?array}|null Null when the page couldn't be fetched at all (blocked, network failure, ...).
     */
    private function jpcProductPage(string $url, bool $splitTitleOnDash = false): ?array
    {
        $html = $this->jpcGet($url);

        if ($html === null) {
            return null;
        }

        $xpath = $this->xpathFor($html);
        $fromTitleTag = $this->parseJpcTitleTag($xpath->query('//title')->item(0)?->textContent, $splitTitleOnDash);
        // UPC/EAN: (CD/film) or ISBN-13: (book, no UPC/EAN row observed there) — see this trait's own docblock.
        $ean = $this->jpcDetailValue($xpath, 'UPC/EAN:') ?? $this->jpcDetailValue($xpath, 'ISBN-13:');
        $offer = $this->jpcPrice($xpath);

        return [
            'title' => $fromTitleTag['title'],
            'byline' => $fromTitleTag['byline'],
            'format' => $fromTitleTag['format'],
            // GitHub issue #136: jpc.de has no dedicated disc-count label
            // at all — the count lives only inside this same
            // title-tag-derived format string (e.g. "2 DVDs", "2 LPs"),
            // confirmed on real multi-disc DVD and LP releases; a
            // single-disc format ("CD", "Blu-ray", "Blu-ray & DVD im
            // Steelbook") has no leading digit, so this stays null there
            // — the same parseLeadingInt() reuse every other leading-
            // number field in this trait already uses.
            'disc_count' => $this->parseLeadingInt($fromTitleTag['format']),
            'ean' => $ean !== null ? preg_replace('/\D/', '', $ean) ?: null : null,
            'release_date' => $this->parseJpcDate($this->jpcDetailValue($xpath, 'Erscheinungstermin:')),
            'runtime_minutes' => $this->parseLeadingInt($this->jpcDetailValue($xpath, 'Spieldauer ca.:')),
            'languages' => $this->jpcDetailValue($xpath, 'Sprache:'),
            'director' => $this->jpcDetailValue($xpath, 'Regie:'),
            'genre' => $this->jpcDetailValue($xpath, 'Genre:'),
            'publisher' => $this->stripJpcPublisherSuffix($this->jpcDetailValue($xpath, 'Verlag:')),
            'page_count' => $this->parseLeadingInt($this->jpcDetailValue($xpath, 'Umfang:')),
            'binding' => $this->jpcDetailValue($xpath, 'Einband:'),
            'price' => $offer['price'],
            'currency' => $offer['currency'],
            'tracks' => $this->jpcTracks($xpath),
        ];
    }

    /**
     * The confirmed `{Artist}: {Titel} ({Format}) – jpc.de` / `{Titel} -
     * {Autor} ({Format}) – jpc.de` / `{Titel} ({Format}) – jpc.de` shapes
     * — see this trait's own docblock for exactly which media type uses
     * which. Colon-splitting (byline first) is always tried; hyphen-
     * splitting (byline last) only when `$splitTitleOnDash` is true, and
     * on the *last* " - " occurrence specifically (a book title itself
     * could plausibly contain its own " - ", but an author name
     * essentially never does, so anchoring on the last occurrence is the
     * safer of the two ends to split on).
     *
     * @return array{title: ?string, byline: ?string, format: ?string}
     */
    private function parseJpcTitleTag(?string $titleTag, bool $splitTitleOnDash = false): array
    {
        $empty = ['title' => null, 'byline' => null, 'format' => null];

        $cleaned = $this->cleanText($titleTag);
        if ($cleaned === null) {
            return $empty;
        }

        if (! preg_match('/^(.+?)\s*\(([^)]*)\)\s*[–-]\s*jpc\.de$/u', $cleaned, $matches)) {
            return ['title' => $cleaned, 'byline' => null, 'format' => null];
        }

        $mainText = trim($matches[1]);
        $format = $this->cleanText($matches[2]);
        $dashPosition = $splitTitleOnDash ? mb_strrpos($mainText, ' - ') : false;

        if (str_contains($mainText, ': ')) {
            [$byline, $title] = array_map('trim', explode(': ', $mainText, 2));
        } elseif ($dashPosition !== false) {
            $title = mb_substr($mainText, 0, $dashPosition);
            $byline = mb_substr($mainText, $dashPosition + 3);
        } else {
            $byline = null;
            $title = $mainText;
        }

        return ['title' => $this->cleanText($title), 'byline' => $this->cleanText($byline), 'format' => $format];
    }

    /**
     * Finds the value for a confirmed real label (e.g. `"Regie:"`) — see
     * this trait's own docblock for the two real DOM shapes GitHub issue
     * #135's byte-exact `curl` re-verification actually found (mixed on
     * the very same page):
     *
     *  1. Label and value share one element's own text (e.g. a `<td>Regie:
     *     Hayao Miyazaki</td>`) — the element's own text, with the label
     *     prefix stripped, is the value.
     *  2. The label is its own `<dt>`/`<th>`, real markup shows both a
     *     bare `<dt>Verlag:</dt>` and a `<dt><b>UPC/EAN:</b></dt>` (label
     *     text wrapped in an inline tag), and the value is that `<dt>`'s
     *     next sibling *element*'s text (skipping over whitespace-only
     *     text nodes in between). GitHub issue #135: the previous version
     *     of this method matched on any element's own direct `text()`
     *     node here, which — for a `<b>`-wrapped label — matched the
     *     inner `<b>` itself rather than the enclosing `<dt>`; `<b>` has
     *     no next sibling *inside* `<dt>`, so the value lookup silently
     *     found nothing for every label jpc.de happens to wrap that way,
     *     which turned out to be most of them. Fixed by matching
     *     specifically on `<dt>`/`<th>` elements (never on an inner
     *     inline-formatting tag) using each one's full, descendant-
     *     inclusive text (`string(.)`, not `text()`) — correct whether or
     *     not that element wraps its own label text in a `<b>`.
     *
     * Returns null when neither shape matches anywhere on the page,
     * exactly like every other opportunistic field source in this app.
     */
    private function jpcDetailValue(DOMXPath $xpath, string $label): ?string
    {
        $escapedLabel = str_replace('"', '\"', $label);

        foreach ($xpath->query('//*[contains(text(), "'.$escapedLabel.'")]') as $node) {
            $text = $this->cleanText($node->textContent);
            if ($text !== null && str_starts_with($text, $label)) {
                $value = $this->cleanText(mb_substr($text, mb_strlen($label)));
                if ($value !== null) {
                    return $value;
                }
            }
        }

        foreach ($xpath->query('//dt[normalize-space(string(.))="'.$escapedLabel.'"] | //th[normalize-space(string(.))="'.$escapedLabel.'"]') as $node) {
            $sibling = $node->nextSibling;
            while ($sibling !== null && ! $sibling instanceof DOMElement) {
                $sibling = $sibling->nextSibling;
            }
            if ($sibling instanceof DOMElement) {
                $value = $this->cleanText($sibling->textContent);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /** German `dd.mm.yyyy` (e.g. "14.8.2026", the confirmed real format) — falls back to a generic parse for any other shape, same defensiveness as AmazonScraping::parseAmazonDate()/ThaliaScraping::normalizeThaliaDate(). */
    private function parseJpcDate(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        if (preg_match('#^(\d{1,2})\.(\d{1,2})\.(\d{4})$#', trim($text), $matches)) {
            return sprintf('%04d-%02d-%02d', (int) $matches[3], (int) $matches[2], (int) $matches[1]);
        }

        $timestamp = strtotime($text);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    /** Pulls the leading integer out of a free-text value like "119 Min." — same as AmazonScraping::parseLeadingInt(). */
    private function parseLeadingInt(?string $text): ?int
    {
        if ($text !== null && preg_match('/^([\d,]+)/', $text, $matches)) {
            return (int) str_replace(',', '', $matches[1]);
        }

        return null;
    }

    /** A confirmed `Verlag:` value is `"{Verlag}, MM/YYYY"` (e.g. "DuMont Buchverlag GmbH, 07/2022") — strips the trailing date so `publisher` doesn't carry it too (`release_date` already comes from the separate, more precise `Erscheinungstermin:` row). Returns the original trimmed string unchanged if it doesn't match that shape, same restraint as AmazonScraping::stripPublisherSuffix(). */
    private function stripJpcPublisherSuffix(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $withoutDate = preg_replace('#,\s*\d{1,2}/\d{4}\s*$#', '', $text) ?? $text;

        return $this->cleanText($withoutDate) ?? $this->cleanText($text);
    }

    /**
     * The confirmed EAN/ISBN-13-derived cover URL pattern — see this
     * trait's own docblock. `w2400` (GitHub issue #135, replacing the
     * originally-used `w468`): the real main-image `<a href>` on all
     * three real pages re-checked for #135 links to a `w2400` version of
     * the same image, the actual full-resolution cover rather than a
     * pre-shrunk thumbnail. Always attempted whenever a code is known;
     * never verified to actually resolve to a real image for every
     * product (a missing cover would simply 404 client-side like any
     * other broken image).
     */
    private function jpcCoverUrl(?string $ean): ?string
    {
        return $ean !== null ? "https://media1.jpc.de/image/w2400/front/0/{$ean}.jpg" : null;
    }

    /**
     * GitHub issue #135: reverses #130's original decision not to extract
     * price/currency at all — that decision was based on incomplete
     * research (see this trait's own docblock), and a byte-exact re-check
     * found a real, confirmed source: a `schema.org/Offer`-typed block
     * (`itemprop="offers"`) carrying `<meta itemprop="price" content="…">`
     * / `<meta itemprop="priceCurrency" content="…">`, present on every
     * real page checked. Explicitly excludes any such `meta` tag nested
     * inside an `itemprop="isSimilarTo"` block (jpc.de shows related
     * editions of the same release further down the page, each with its
     * own nested Offer) so a related edition's price is never mistaken
     * for this product's own.
     *
     * @return array{price: ?float, currency: ?string}
     */
    private function jpcPrice(DOMXPath $xpath): array
    {
        $priceNode = $xpath->query('//meta[@itemprop="price"][not(ancestor::*[@itemprop="isSimilarTo"])]')->item(0);
        $currencyNode = $xpath->query('//meta[@itemprop="priceCurrency"][not(ancestor::*[@itemprop="isSimilarTo"])]')->item(0);

        $price = $priceNode instanceof DOMElement ? $priceNode->getAttribute('content') : null;
        $currency = $currencyNode instanceof DOMElement ? $this->cleanText($currencyNode->getAttribute('content')) : null;

        return [
            'price' => is_numeric($price) ? (float) $price : null,
            'currency' => $currency,
        ];
    }

    /**
     * GitHub issue #135: reverses #130's original decision not to attempt
     * a CD track listing (`AmazonCdProvider`'s own "can't confirm this
     * reliably" restraint, applied here too at the time on the same
     * assumption) — a byte-exact re-check found a real, confirmed source:
     * `<li itemscope itemtype="https://schema.org/MusicRecording"
     * itemprop="track">` items, each with a plain visible position number
     * and a title (`itemprop="name"`). No duration is present anywhere in
     * this markup, so `duration_seconds` is always null — matching the
     * MediaCd `tracks` shape (`position`/`title`/`duration_seconds`) every
     * other provider that populates this field already uses, just with
     * this one column always empty here. Same `isSimilarTo` exclusion as
     * jpcPrice() — irrelevant in practice (a related edition's own track
     * listing isn't shown inline), kept for the same defensive reason.
     * Returns null (not an empty array) when the page has no track
     * microdata at all (a book or film page, or a CD page that simply
     * doesn't expose one) — distinguishing "no track data on this page"
     * from "confirmed zero tracks", which callers should treat as
     * uninformative either way.
     *
     * @return array<int, array{position: ?string, title: ?string, duration_seconds: ?int}>|null
     */
    private function jpcTracks(DOMXPath $xpath): ?array
    {
        $nodes = $xpath->query('//li[@itemtype="https://schema.org/MusicRecording"][not(ancestor::*[@itemprop="isSimilarTo"])]');

        if ($nodes->length === 0) {
            return null;
        }

        $tracks = [];

        foreach ($nodes as $node) {
            $positionNode = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " tracks ")]/b', $node)->item(0);
            $nameNode = $xpath->query('.//*[@itemprop="name"]', $node)->item(0);

            $tracks[] = [
                'position' => $positionNode instanceof DOMElement ? $this->cleanText($positionNode->textContent) : null,
                'title' => $nameNode instanceof DOMElement ? $this->cleanText($nameNode->textContent) : null,
                'duration_seconds' => null,
            ];
        }

        return $tracks;
    }

    /** Extracts the numeric `hnum` product identifier this trait's own docblock confirms is embedded in every product URL — falls back to the full URL if a page doesn't follow that shape. */
    private function jpcProductId(string $url): string
    {
        return preg_match('#/hnum/(\d+)#', $url, $matches) ? $matches[1] : $url;
    }

    private function absoluteJpcUrl(string $href): ?string
    {
        if ($href === '') {
            return null;
        }

        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        return str_starts_with($href, '/') ? self::BASE_URL.$href : null;
    }

    /**
     * GET with a standard desktop-browser User-Agent and de-DE content
     * negotiation. Best-effort like every other provider's request(): any
     * non-2xx status, transport failure, or empty body is simply "no
     * result", logged, never thrown.
     */
    private function jpcGet(string $url, array $query = []): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept-Language' => 'de-DE,de;q=0.9',
            ])->timeout(self::TIMEOUT_SECONDS)->get($url, $query);
        } catch (\Throwable $e) {
            Log::info('JPC scrape request failed.', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed() || $response->body() === '') {
            return null;
        }

        return $response->body();
    }

    private function xpathFor(string $html): DOMXPath
    {
        $document = new DOMDocument;
        // Malformed/HTML5-only markup is expected and not a real error —
        // same defensiveness as AmazonScraping::xpathFor().
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    /** Same whitespace/bidi-mark cleanup as AmazonScraping::cleanText()/ThaliaScraping::cleanText() — duplicated rather than shared, matching this codebase's existing convention of each provider family owning its own self-contained trait. */
    private function cleanText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $withoutBidiMarks = preg_replace('/[\x{200B}-\x{200F}\x{FEFF}]/u', '', $text) ?? $text;
        $collapsed = trim(preg_replace('/\s+/u', ' ', $withoutBidiMarks) ?? '');

        return $collapsed === '' ? null : $collapsed;
    }
}
