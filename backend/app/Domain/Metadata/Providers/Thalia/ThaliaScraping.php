<?php

namespace App\Domain\Metadata\Providers\Thalia;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared HTTP + HTML parsing for the three Thalia providers (ThaliaBook/
 * ThaliaCd/ThaliaDvdBlurayProvider — GitHub issue #129). Structural sibling
 * of AmazonScraping (GitHub issue #50): thalia.de has no public lookup API
 * either, so this scrapes the same product/search pages a browser would
 * load — **explicitly a Beta feature**, disabled by default, for the same
 * legal/technical/maintenance reasons AmazonScraping's own docblock lays
 * out in full (not repeated here). Unlike Amazon (briefing 8.2), thalia.de
 * isn't a source the briefing itself names — this exists purely because it
 * was explicitly requested, for the same reason Amazon originally was:
 * a large retailer without a usable public API.
 *
 * ## What's confirmed vs. guessed here — read this before trusting any of it
 *
 * AmazonScraping's own docblock explains why it was never live-fetched
 * during development (repeatedly scraping the real site purely to build a
 * scraper would itself be the scraping traffic the feature's risk profile
 * is about) and was instead built from general, broadly-documented
 * knowledge of Amazon's page structure. thalia.de is a much smaller,
 * single-market site with no comparable body of public documentation to
 * build from that way — so, at the requesting user's explicit direction,
 * this was instead grounded in a one-time, read-only check of what's
 * *already public* about thalia.de before writing any scraping code:
 * indexed search-result snippets of real thalia.de pages (titles/URLs
 * already crawled and shown by a search engine, not a direct request to
 * thalia.de itself) rather than fetching the live site directly — every
 * direct fetch attempted against thalia.de during that check (homepage,
 * robots.txt, the search page, two different product pages) returned a
 * flat HTTP 403, confirming active bot-blocking at the edge more
 * concretely than was ever established for Amazon.
 *
 * What that check actually confirmed, independently, across many real,
 * distinct products:
 *  - **Product-detail URLs** follow `/shop/home/artikeldetails/
 *    {category-slug}/EAN{ean}/ID{id}.html`, with a shorter `/
 *    artikeldetails/A{id}` alias also seen in the wild.
 *  - **The `<title>` tag** consistently follows `{Titel} von {Autor/
 *    Regisseur} - {Format} - {ISBN oder EAN} | Thalia` (the code segment
 *    is sometimes absent) — confirmed across dozens of independent real
 *    book and Blu-ray listings, making it the single most reliable
 *    extraction anchor available here, parsed by parseThaliaTitleTag()
 *    below.
 *  - **A search page exists** at `/shop/home/suche`, its own page title
 *    explicitly advertising search "nach Artikel, Autor oder ISBN".
 *
 * What was **not** confirmed and is a deliberate best-effort guess:
 *  - **The search query parameter** (SEARCH_QUERY_PARAM below) — no
 *    fetchable page ever revealed it. `sq` was chosen as a plausible
 *    guess (a pattern seen on other, unrelated retail platforms), but
 *    this is genuinely unverified and the single most likely reason this
 *    provider might return nothing at all on a real deployment; an
 *    operator/admin hitting that is the expected first real-world
 *    adjustment this class will need.
 *  - **thaliaSearch()'s results-page markup** — since no real
 *    search-results HTML was ever seen (search itself 403's the same as
 *    everything else), this doesn't guess at any specific container/
 *    class the way AmazonScraping's `s-search-result` does. It instead
 *    matches on the one thing independently confirmed above — any anchor
 *    whose `href` contains `/artikeldetails/` — which is structure-
 *    agnostic by design and should survive a results-page redesign that
 *    would break a class/id-based selector outright.
 *  - **schema.org JSON-LD (`<script type="application/ld+json">`) and
 *    Open Graph meta tags** (`og:title`/`og:image`/`og:description`) are
 *    used as a *secondary*, opportunistic source for fields the title tag
 *    doesn't carry (description, cover image, price, publisher, page
 *    count, language, runtime). Their presence on thalia.de specifically
 *    was never confirmed either — but both are open, spec-documented,
 *    widely-adopted e-commerce/SEO conventions independent of this one
 *    site, not a guess at thalia.de's own private markup, so relying on
 *    them (opportunistically, every field still nullable) is a more
 *    defensible bet than inventing thalia.de-specific selectors with no
 *    evidence at all.
 *
 * Every field extracted here is nullable/best-effort for the same reason
 * AmazonScraping's fields are — expect this to need real-world adjustment
 * more than any other provider in this app, Amazon included.
 *
 * `de-DE` is requested (Accept-Language) since thalia.de is a
 * German-market site and the confirmed title-tag format itself is German
 * ("von", not "by").
 */
trait ThaliaScraping
{
    private const BASE_URL = 'https://www.thalia.de';

    private const SEARCH_PATH = '/shop/home/suche';

    /** Unverified best guess — see this trait's own docblock. */
    private const SEARCH_QUERY_PARAM = 'sq';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private const TIMEOUT_SECONDS = 10;

    /**
     * Parses thalia.de's search-results page for a query (a free-text
     * search or, for lookupByCode(), the scanned EAN/ISBN itself). Matches
     * generically on any `/artikeldetails/`-containing anchor rather than a
     * guessed results-container shape — see this trait's own docblock for
     * why. When the same product URL appears via more than one anchor (a
     * common "image links to product, title links to product" pattern),
     * whichever anchor actually carried non-empty text wins the title.
     *
     * Null (not an empty array) specifically means the request itself
     * didn't succeed (network failure, non-2xx, a block) — same
     * 'failed' vs. 'no_match' distinction AmazonScraping::amazonSearch()
     * documents (GitHub issue #53).
     *
     * @return array<int, array{url: string, title: ?string, thumbnail_url: ?string}>|null
     */
    private function thaliaSearch(string $query): ?array
    {
        $html = $this->thaliaGet(self::BASE_URL.self::SEARCH_PATH, [self::SEARCH_QUERY_PARAM => $query]);

        if ($html === null) {
            return null;
        }

        $xpath = $this->xpathFor($html);
        $results = [];
        $indexByUrl = [];

        foreach ($xpath->query('//a[contains(@href, "/artikeldetails/")]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $url = $this->absoluteThaliaUrl($node->getAttribute('href'));
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
     * Parses one product page into its most reliably extractable fields —
     * see this trait's own docblock for exactly which parts are confirmed
     * (the `<title>` tag) vs. opportunistic best-effort (JSON-LD/Open
     * Graph). Unlike AmazonScraping::amazonProductPage()'s single stable
     * "detail bullets" label/value map, there's no equivalent confirmed
     * shape here, so fields are pulled from whichever of the three sources
     * actually has them, title tag taking priority for the fields it
     * reliably carries.
     *
     * `price`/`currency` (GitHub issue #58's pattern, mirrored here) come
     * only from JSON-LD `offers` — the title tag never carries a price —
     * so both stay null whenever no JSON-LD offer was found, exactly like
     * every other opportunistic field.
     *
     * @return array{title: ?string, byline: ?string, format: ?string, isbn_or_ean: ?string, description: ?string, cover_url: ?string, publisher: ?string, language: ?string, page_count: ?int, release_date: ?string, runtime_minutes: ?int, price: ?float, currency: ?string}|null Null when the page couldn't be fetched at all (blocked, network failure, ...).
     */
    private function thaliaProductPage(string $url): ?array
    {
        $html = $this->thaliaGet($url);

        if ($html === null) {
            return null;
        }

        $xpath = $this->xpathFor($html);
        $fromTitleTag = $this->parseThaliaTitleTag($xpath->query('//title')->item(0)?->textContent);
        $ld = $this->thaliaJsonLd($xpath) ?? [];
        $offer = $this->jsonLdOffer($ld);
        $price = isset($offer['price']) && is_numeric($offer['price']) ? (float) $offer['price'] : null;

        return [
            'title' => $this->cleanText($ld['name'] ?? null) ?? $fromTitleTag['title'] ?? $this->thaliaMeta($xpath, 'og:title'),
            'byline' => $fromTitleTag['byline'] ?? $this->jsonLdNames($ld['author'] ?? $ld['byArtist'] ?? $ld['director'] ?? $ld['creator'] ?? null),
            'format' => $fromTitleTag['format'] ?? $this->firstString($ld, ['bookFormat']),
            'isbn_or_ean' => $fromTitleTag['isbn_or_ean'] ?? $this->firstString($ld, ['isbn', 'gtin13', 'gtin', 'sku']),
            'description' => $this->cleanText($ld['description'] ?? null) ?? $this->thaliaMeta($xpath, 'og:description'),
            'cover_url' => $this->jsonLdImageUrl($ld['image'] ?? null) ?? $this->thaliaMeta($xpath, 'og:image'),
            'publisher' => $this->jsonLdNames($ld['publisher'] ?? null),
            'language' => $this->firstString($ld, ['inLanguage']),
            'page_count' => isset($ld['numberOfPages']) && is_numeric($ld['numberOfPages']) ? (int) $ld['numberOfPages'] : null,
            'release_date' => $this->normalizeThaliaDate($this->firstString($ld, ['datePublished'])),
            'runtime_minutes' => $this->parseIso8601DurationMinutes($ld['duration'] ?? null),
            'price' => $price,
            'currency' => $price !== null ? ($this->firstString($offer, ['priceCurrency']) ?? 'EUR') : null,
        ];
    }

    /**
     * The one field-extraction step confirmed against real thalia.de pages
     * (see this trait's own docblock) — splits `"{Titel} von {Person} -
     * {Format} - {Code} | Thalia"` (the code and even the trailing
     * `| Thalia` are sometimes absent) into its parts. Falls back to
     * treating the whole (cleaned) string as the title alone when it
     * doesn't contain " von " at all, rather than discarding it.
     *
     * @return array{title: ?string, byline: ?string, format: ?string, isbn_or_ean: ?string}
     */
    private function parseThaliaTitleTag(?string $titleTag): array
    {
        $empty = ['title' => null, 'byline' => null, 'format' => null, 'isbn_or_ean' => null];

        $cleaned = $this->cleanText($titleTag);
        if ($cleaned === null) {
            return $empty;
        }

        $withoutSiteName = preg_replace('/\s*\|\s*Thalia\s*$/u', '', $cleaned) ?? $cleaned;
        $segments = array_map('trim', explode(' - ', $withoutSiteName));
        $first = array_shift($segments) ?? '';

        if (! preg_match('/^(.+?)\s+von\s+(.+)$/u', $first, $matches)) {
            return ['title' => $this->cleanText($first), 'byline' => null, 'format' => null, 'isbn_or_ean' => null];
        }

        $format = $segments[0] ?? null;
        $codeCandidate = $segments[1] ?? null;
        $isbnOrEan = $codeCandidate !== null && preg_match('/^[\dXx-]{10,}$/', $codeCandidate) ? $codeCandidate : null;

        return [
            'title' => $this->cleanText($matches[1]),
            'byline' => $this->cleanText($matches[2]),
            'format' => $this->cleanText($format),
            'isbn_or_ean' => $isbnOrEan,
        ];
    }

    /**
     * Finds the first `<script type="application/ld+json">` block whose
     * decoded content — either the block itself, an item of a top-level
     * array, or an item of a `@graph` array (all real shapes JSON-LD
     * publishers use) — declares an `@type` plausible for a retail
     * product page. Returns null (not an error) when no block matches,
     * exactly like every other opportunistic field source here — see this
     * trait's own docblock for why this was never confirmed to exist on
     * thalia.de specifically.
     */
    private function thaliaJsonLd(DOMXPath $xpath): ?array
    {
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
            $decoded = json_decode($node->textContent ?? '', true);
            if (! is_array($decoded)) {
                continue;
            }

            $candidates = is_array($decoded['@graph'] ?? null)
                ? $decoded['@graph']
                : (array_is_list($decoded) ? $decoded : [$decoded]);

            foreach ($candidates as $item) {
                if (is_array($item) && in_array($item['@type'] ?? null, ['Product', 'Book', 'MusicAlbum', 'Movie', 'CreativeWork'], true)) {
                    return $item;
                }
            }
        }

        return null;
    }

    /** A JSON-LD `offers` value is either one Offer object or an array of them — this always returns a single one (the first, if a list) to read `price`/`priceCurrency` from. */
    private function jsonLdOffer(array $ld): array
    {
        $offers = $ld['offers'] ?? null;
        if (! is_array($offers)) {
            return [];
        }

        return array_is_list($offers) ? (is_array($offers[0] ?? null) ? $offers[0] : []) : $offers;
    }

    /** A JSON-LD person/organization-valued property (author, byArtist, director, publisher, ...) is a bare string, a single {"name": ...} object, or an array of either — normalizes all of those into one comma-joined display string. */
    private function jsonLdNames(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $items = array_is_list($value) ? $value : [$value];
        $names = [];

        foreach ($items as $item) {
            if (is_string($item)) {
                $names[] = $item;
            } elseif (is_array($item) && is_string($item['name'] ?? null)) {
                $names[] = $item['name'];
            }
        }

        return $this->cleanText(implode(', ', $names));
    }

    /** A JSON-LD `image` value is a bare URL string, an array of URL strings, or an ImageObject ({"url": ...}) — this always returns just the first usable URL. */
    private function jsonLdImageUrl(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            $first = array_is_list($value) ? ($value[0] ?? null) : $value;
            if (is_string($first)) {
                return $first;
            }
            if (is_array($first) && is_string($first['url'] ?? null)) {
                return $first['url'];
            }
        }

        return null;
    }

    /** @param  array<string>  $keys */
    private function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (is_string($data[$key] ?? null) && trim($data[$key]) !== '') {
                return trim($data[$key]);
            }
        }

        return null;
    }

    /** JSON-LD dates are normally already ISO 8601 (`YYYY-MM-DD`), but this re-parses rather than trusting that blindly, same defensiveness as AmazonScraping::parseAmazonDate(). */
    private function normalizeThaliaDate(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $timestamp = strtotime($text);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    /** A JSON-LD `duration` (e.g. a film's runtime) is an ISO 8601 duration like "PT136M" or "PT2H16M" — this reads only the hour/minute components, which is all a film runtime ever needs. */
    private function parseIso8601DurationMinutes(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?/', $value, $matches)) {
            return null;
        }

        $hours = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
        $minutes = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;

        return $hours * 60 + $minutes ?: null;
    }

    private function thaliaMeta(DOMXPath $xpath, string $property): ?string
    {
        $node = $xpath->query('//meta[@property="'.$property.'"]')->item(0);

        return $node instanceof DOMElement ? $this->cleanText($node->getAttribute('content')) : null;
    }

    /** Extracts the `ID{n}`/`A{n}` product identifier this trait's own docblock confirms is embedded in every product URL — falls back to the full URL on the (untested) chance a page doesn't follow that shape, so this never throws. */
    private function thaliaProductId(string $url): string
    {
        return preg_match('#/(ID\d+|A\d+)(?:\.html)?(?:[/?].*)?$#', $url, $matches) ? $matches[1] : $url;
    }

    private function absoluteThaliaUrl(string $href): ?string
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
     * negotiation — see this trait's own docblock for why German, unlike
     * AmazonScraping's en-US. Best-effort like every other provider's
     * request(): any non-2xx status, transport failure, or empty body is
     * simply "no result", logged, never thrown.
     */
    private function thaliaGet(string $url, array $query = []): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept-Language' => 'de-DE,de;q=0.9',
            ])->timeout(self::TIMEOUT_SECONDS)->get($url, $query);
        } catch (\Throwable $e) {
            Log::info('Thalia scrape request failed.', ['url' => $url, 'error' => $e->getMessage()]);

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

    /** Same bidi-mark/whitespace cleanup as AmazonScraping::cleanText() — duplicated rather than shared, matching this codebase's existing convention of each provider family owning its own self-contained trait. */
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
