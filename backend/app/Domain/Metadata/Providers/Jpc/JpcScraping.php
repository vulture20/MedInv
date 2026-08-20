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
 * it does). Structural sibling of AmazonScraping (#50) and ThaliaScraping
 * (#129): jpc.de has no public lookup API either, so this scrapes the
 * same product/search pages a browser would load — **explicitly a Beta
 * feature**, disabled by default, for the same legal/technical/
 * maintenance reasons AmazonScraping's own docblock lays out in full (not
 * repeated here). Like Thalia, jpc.de isn't a briefing-listed source —
 * added purely because it was explicitly requested.
 *
 * ## What's confirmed vs. guessed here — read this before trusting any of it
 *
 * Unlike thalia.de (#129), jpc.de did **not** block a direct, one-time,
 * read-only fetch during this check — homepage, robots.txt, and several
 * real product pages all loaded normally, which made it possible to
 * confirm meaningfully more here than for Thalia, though a full raw-HTML
 * dump was never available either way (only a summarized read of each
 * fetched page) — so even the "confirmed" items below are read from a
 * summary of the real page, not a byte-for-byte markup diff. Confirmed,
 * independently, across three real, distinct product pages (one CD, one
 * Blu-ray/DVD, one book — the book page checked separately for #131):
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
 *    `https://media1.jpc.de/image/w468/front/0/{ean-or-isbn13}.jpg`,
 *    confirmed on two real product pages (one CD by EAN, one book by
 *    ISBN-13) — unlike Amazon/Thalia, this needs no `<img>`/`og:image`
 *    extraction at all once a code is known.
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
 *    `Umfang:` (page count, e.g. "176 Seiten"), `Erscheinungstermin:`. No
 *    `<script type="application/ld+json">` block or `og:*` meta tags were
 *    found on any page checked — unlike ThaliaScraping, there's no
 *    schema.org/Open Graph fallback to lean on here, so every field below
 *    comes from the title tag or these label rows alone. Notably, no
 *    description/blurb of any kind was found on the one real book page
 *    checked either — `description` is never populated for any of the
 *    three media types this trait supports, not just cd/dvd_bluray.
 *
 * What was **not** confirmed and is a deliberate best-effort guess or
 * omission:
 *  - **The exact DOM shape around a confirmed label** (e.g. whether
 *    `Regie:` and its value share one element's text, or sit in separate
 *    `dt`/`dd`-style siblings) was never actually visible — only that the
 *    label *text* appears somewhere on the page. `jpcDetailValue()` below
 *    therefore tries both shapes generically rather than assuming either
 *    one, the same "structure-agnostic by design" reasoning
 *    ThaliaScraping's `/artikeldetails/`-anchor search matching uses.
 *  - **The search endpoint** — `/jpcng/home/searchform` is a real,
 *    confirmed "advanced search" landing page, but not the actual
 *    results endpoint a query submits to; several plausible guesses
 *    (`/jpcng/search?q=`, `/jpcng/quicksearch?searchterm=`, `/jpcng/
 *    search/-/query/`) were tried directly and either 404'd or hit an
 *    inconclusive 503, so SEARCH_PATH/SEARCH_QUERY_PARAM below remain an
 *    honest guess, not a confirmed dead end ruled out for a *specific*
 *    reason the way those three attempts were. This — even more than for
 *    Thalia, where at least a real search *page* was confirmed to exist
 *    — is the single most likely reason this provider might return
 *    nothing at all on a real deployment.
 *  - **`jpcSearch()`'s results-page markup** — never seen at all (the
 *    search endpoint itself couldn't be confirmed), so, like
 *    ThaliaScraping::thaliaSearch(), this matches generically on any
 *    anchor whose `href` contains the one confirmed constant,
 *    `/detail/-/art/`, rather than guessing at a results-container
 *    class.
 *  - **Price/currency are deliberately never extracted at all** — unlike
 *    every field above, no confirmed label or container for the price
 *    was found on any real page checked (it showed up only in an
 *    already-summarized page read, with no indication of where in the
 *    markup it actually lives), and a blind whole-document regex for
 *    "EUR d+,dd" risks silently grabbing an unrelated price elsewhere on
 *    the page (a shipping note, a related-product carousel, ...) — a
 *    wrong price is a worse outcome than a missing one, so this simply
 *    isn't attempted, the same restraint AmazonCdProvider already applies
 *    to `tracks` for a field it can't confirm reliably.
 *  - **`cast` (DVD/Blu-ray)** — no "Darsteller"/"Besetzung"-style label
 *    was observed on the one real film page checked, so, unlike
 *    AmazonDvdBlurayProvider (which has a confirmed "Actors" bullet and a
 *    byline fallback), JpcDvdBlurayProvider never sets this field at all
 *    rather than guessing at an unconfirmed label.
 *  - **`Label:` (record label)** was confirmed as a real CD detail-row
 *    label but is never extracted — neither `MediaCd` nor any other
 *    in-scope model has a fillable column it would map to.
 *
 * Every field extracted here is nullable/best-effort for the same reason
 * AmazonScraping's/ThaliaScraping's fields are.
 *
 * `de-DE` is requested (Accept-Language) since jpc.de is a German-market
 * site and every confirmed label above is German.
 */
trait JpcScraping
{
    private const BASE_URL = 'https://www.jpc.de';

    /** Unverified guess — see this trait's own docblock. */
    private const SEARCH_PATH = '/jpcng/search';

    /** Unverified guess — see this trait's own docblock. */
    private const SEARCH_QUERY_PARAM = 'searchtext';

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
     * labels) vs. best-effort (the DOM shape around those labels; the
     * EAN/ISBN-derived cover URL always attempted regardless of whether
     * the page actually has that product's image).
     *
     * @param  bool  $splitTitleOnDash  Opt into the confirmed book-only `{Titel} - {Autor}` title-tag convention — see this trait's own docblock for why this isn't applied unconditionally to every media type. Only JpcBookProvider passes true.
     *
     * `price`/`currency` and `description` are deliberately never
     * populated — see this trait's own docblock. `Label:` (record label)
     * was confirmed as a real CD detail-row label too, but isn't
     * extracted here at all: no in-scope model has a fillable column it
     * would map to — extracting a value with nowhere to put it would just
     * be dead code.
     * @return array{title: ?string, byline: ?string, format: ?string, ean: ?string, release_date: ?string, runtime_minutes: ?int, languages: ?string, director: ?string, genre: ?string, publisher: ?string, page_count: ?int, binding: ?string}|null Null when the page couldn't be fetched at all (blocked, network failure, ...).
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

        return [
            'title' => $fromTitleTag['title'],
            'byline' => $fromTitleTag['byline'],
            'format' => $fromTitleTag['format'],
            'ean' => $ean !== null ? preg_replace('/\D/', '', $ean) ?: null : null,
            'release_date' => $this->parseJpcDate($this->jpcDetailValue($xpath, 'Erscheinungstermin:')),
            'runtime_minutes' => $this->parseLeadingInt($this->jpcDetailValue($xpath, 'Spieldauer ca.:')),
            'languages' => $this->jpcDetailValue($xpath, 'Sprache:'),
            'director' => $this->jpcDetailValue($xpath, 'Regie:'),
            'genre' => $this->jpcDetailValue($xpath, 'Genre:'),
            'publisher' => $this->stripJpcPublisherSuffix($this->jpcDetailValue($xpath, 'Verlag:')),
            'page_count' => $this->parseLeadingInt($this->jpcDetailValue($xpath, 'Umfang:')),
            'binding' => $this->jpcDetailValue($xpath, 'Einband:'),
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
     * Finds the value for a confirmed real label (e.g. `"Regie:"`) without
     * assuming which of two plausible DOM shapes it's actually in — see
     * this trait's own docblock for why neither shape was confirmed:
     *
     *  1. Label and value share one element's text (e.g. a `<td>Regie:
     *     Hayao Miyazaki</td>`) — the element's own text, with the label
     *     prefix stripped, is the value.
     *  2. The label is its own element (e.g. a `<dt>`/`<th>`) and the
     *     value is the next sibling *element*'s text (skipping over
     *     whitespace-only text nodes in between).
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

        foreach ($xpath->query('//*[normalize-space(text())="'.$escapedLabel.'"]') as $node) {
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

    /** The confirmed EAN/ISBN-13-derived cover URL pattern — see this trait's own docblock. Always attempted whenever a code is known; never verified to actually resolve to a real image for every product (a missing cover would simply 404 client-side like any other broken image). */
    private function jpcCoverUrl(?string $ean): ?string
    {
        return $ean !== null ? "https://media1.jpc.de/image/w468/front/0/{$ean}.jpg" : null;
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
