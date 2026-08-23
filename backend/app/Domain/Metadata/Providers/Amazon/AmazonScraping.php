<?php

namespace App\Domain\Metadata\Providers\Amazon;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared HTTP + HTML parsing for the three Amazon providers (AmazonBook/
 * AmazonCd/AmazonDvdBlurayProvider — GitHub issue #50, briefing 8.2 lists
 * Amazon for all three media types). Amazon has no public lookup API
 * usable by a self-hosted project without an active affiliate/sales
 * relationship (unlike every other source in this app), so this scrapes
 * the same product/search pages a browser would load — **explicitly a
 * Beta feature** (see each provider's own `version()`, "-beta"-suffixed;
 * no longer echoed in `name()` too, removed per explicit user request as
 * redundant with the plugin list's own Version column), for reasons laid
 * out in full below and in the issue itself.
 *
 * ## Why this is fundamentally different from every other provider here
 *
 * - **Legal**: Amazon's Conditions of Use prohibit scraping. This class
 *   doesn't attempt to hide that or work around any access control to get
 *   at something not otherwise public — it requests the exact same
 *   publicly served product/search pages a logged-out browser gets — but
 *   ToS violation is a real, separate concern from technical access, and
 *   enabling this plugin is an explicit choice an operator makes for
 *   their own deployment, not something this app does on their behalf
 *   unprompted (see MetadataProviderRegistry::syncToDatabase()'s handling
 *   of these three specific provider keys — unlike every other provider,
 *   not enabled by default on install).
 * - **Technical**: Amazon runs active bot detection (rate limits,
 *   CAPTCHA/"Robot Check" interstitials, IP-based blocks) — a
 *   fundamentally different, much more aggressive category of defense
 *   than the single TLS/HTTP2 client-fingerprint quirk CurlImageFetcher
 *   works around for Discogs' image CDN (that one has no bot-detection
 *   intent behind it at all, confirmed live during that investigation).
 *   This class makes **no attempt to evade or work around that
 *   detection** — no header spoofing beyond an ordinary desktop browser
 *   User-Agent (needed just to receive the normal HTML a browser gets,
 *   the same baseline every other scraping-adjacent tool uses — not
 *   fingerprint evasion), no IP rotation, no CAPTCHA solving, no
 *   randomized human-mimicking delays. A block is treated exactly like
 *   any other lookup failure: logged, empty result, and — per briefing
 *   8.3 — never allowed to block any other enabled provider.
 * - **Markup stability**: unlike a documented, versioned API, Amazon's
 *   page structure can change at any time with no notice, silently
 *   breaking this. Field extraction below deliberately favors matching a
 *   detail row's *visible label text* ("ISBN-13", "Publisher", ...) over
 *   a specific CSS/element id, since labels have stayed far more stable
 *   across Amazon's own historical markup changes than exact container
 *   ids have — but this is still meaningfully more fragile than any API
 *   integration in this codebase, and every field is optional/nullable
 *   for exactly that reason.
 * - **Originally not live-verified, later checked once, deliberately**:
 *   every other provider in this codebase was confirmed against the real
 *   service during development (see e.g. DiscogsProvider's/
 *   MusicBrainzProvider's docblocks); this one originally wasn't, on the
 *   reasoning that repeatedly scraping the real amazon.com purely to
 *   develop/verify this class would itself be exactly the kind of
 *   scraping traffic this feature's own risk profile is about — built and
 *   tested only against hand-built HTML fixtures representative of
 *   Amazon's historically documented markup instead. GitHub issue #137
 *   deliberately made a single, one-time exception to that stance, at the
 *   requesting user's own explicit direction after weighing the same
 *   trade-off this docblock names: **one** real product-page fetch (not a
 *   search, not a second page, not a repeated check) to re-verify the
 *   fixtures this class had never actually been checked against — the
 *   same reasoning already applied once before for ThaliaScraping's own
 *   development (GitHub issue #129) and, in a narrower form, to diagnose
 *   #132's Cloudflare finding. That check found real, confirmed drift
 *   from the original fixtures (see `amazonPriceAndCurrency()`'s and
 *   `amazonProductPage()`'s own docblocks) — concretely bearing out the
 *   "expect this to need real-world adjustment" caution this docblock
 *   already carried — and was not repeated beyond that single fetch; the
 *   rest of this trait's fields remain unconfirmed against live markup,
 *   and are not to be re-checked without the same explicit, one-time
 *   authorization this exception required. GitHub issue #141 later
 *   established a different, lower-cost way to extend this same
 *   authorized-exception model: rather than this trait fetching
 *   amazon.com itself again, the user provided an already-saved real
 *   product-page HTML dump directly — no additional request against
 *   Amazon at all. That dump confirmed `amazonDetailBullets()`'s own
 *   docblock's `#productOverview_feature_div` finding, and, being served
 *   in German (reached via a `/-/de/` URL segment this trait's own
 *   requests never add), incidentally showed several bullet labels in
 *   German rather than English — see `AmazonDvdBlurayProvider`'s docblock
 *   for exactly which fields that did and didn't end up affecting.
 *
 * `en-US`/`en-GB` is explicitly requested (Accept-Language) since field
 * extraction matches English label text — a different Amazon locale would
 * need its own label translations, not attempted here as a rule, though
 * GitHub issue #141 added one narrow, real-evidence-backed exception (a
 * German 'Untertitel' fallback for DVD/Blu-ray subtitles — see
 * `AmazonDvdBlurayProvider`'s docblock) rather than translating every
 * field speculatively.
 */
trait AmazonScraping
{
    private const BASE_URL = 'https://www.amazon.com';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private const TIMEOUT_SECONDS = 10;

    /**
     * Parses Amazon's search-results page for a query (a free-text search
     * or, for lookupByCode(), the scanned EAN/UPC itself — Amazon's search
     * indexes barcodes reasonably well for most in-catalog products, the
     * same "search by code" approach every other provider's lookupByCode()
     * uses under the hood).
     *
     * Null (rather than an empty array) specifically means the underlying
     * request itself didn't succeed (network failure, non-2xx, a block —
     * see amazonGet()) — distinct from a successful request that genuinely
     * found nothing. lookupByCode() (GitHub issue #53) uses that
     * distinction to report a blocked/failed scrape as 'failed' rather than
     * 'no_match'; search() deliberately keeps treating both cases the same
     * (`?? []`), matching this class's documented stance that a block is
     * "treated exactly like any other lookup failure: logged, empty
     * result" for that path.
     *
     * @return array<int, array{asin: string, title: ?string, thumbnail_url: ?string}>|null
     */
    private function amazonSearch(string $query): ?array
    {
        $html = $this->amazonGet(self::BASE_URL.'/s', ['k' => $query]);

        if ($html === null) {
            return null;
        }

        $xpath = $this->xpathFor($html);
        $results = [];

        foreach ($xpath->query('//div[@data-component-type="s-search-result" and @data-asin]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $asin = $node->getAttribute('data-asin');
            if ($asin === '') {
                continue;
            }

            $titleNode = $xpath->query('.//h2//span', $node)->item(0);
            $imageNode = $xpath->query('.//img[contains(@class, "s-image")]', $node)->item(0);

            $results[] = [
                'asin' => $asin,
                'title' => $this->cleanText($titleNode?->textContent),
                'thumbnail_url' => $imageNode instanceof DOMElement ? ($imageNode->getAttribute('src') ?: null) : null,
            ];
        }

        return $results;
    }

    /**
     * Parses one product page (GET /dp/{asin}) into its most reliably
     * extractable fields. Every value is best-effort/nullable — see this
     * trait's docblock for why markup-based extraction can't promise more
     * than that.
     *
     * `bullets` is the product-details list (ISBN, publisher, format,
     * runtime, ...) keyed by whichever *visible label text* Amazon showed,
     * unmodified — amazonBullet() below does the fuzzy/case-insensitive
     * lookup against it, so this stays a dumb, order-preserving map rather
     * than trying to normalize labels itself.
     *
     * `price`/`currency` (GitHub issue #58, re-verified for GitHub issue
     * #137) come from `amazonPriceAndCurrency()` below — see its own
     * docblock for why `currency` is no longer hardcoded to `'USD'`.
     *
     * `description` additionally checks `#bookDescription_feature_div`
     * (GitHub issue #137) — a real book-category-specific container
     * confirmed on a real product page during that issue's live
     * re-check; `#feature-bullets`/`#productDescription` were not found
     * there at all, so without this the description was silently always
     * null for books specifically.
     *
     * `byline` is passed through `stripAmazonFormatContamination()` (GitHub
     * issue #137) — see that method's own docblock for the real trailing
     * "Format: {value}" text confirmed bleeding into it otherwise.
     *
     * @return array{title: ?string, cover_url: ?string, byline: ?string, description: ?string, bullets: array<string, string>, price: ?float, currency: ?string}|null Null when the page couldn't be fetched/parsed at all (blocked, network failure, ...).
     */
    private function amazonProductPage(string $asin): ?array
    {
        $html = $this->amazonGet(self::BASE_URL."/dp/{$asin}");

        if ($html === null) {
            return null;
        }

        $xpath = $this->xpathFor($html);

        $title = $this->cleanText($xpath->query('//*[@id="productTitle"]')->item(0)?->textContent);
        $byline = $this->stripAmazonFormatContamination($this->cleanText($xpath->query('//*[@id="bylineInfo"]')->item(0)?->textContent));
        $description = $this->cleanText(
            $xpath->query('//*[@id="feature-bullets"]')->item(0)?->textContent
                ?? $xpath->query('//*[@id="productDescription"]')->item(0)?->textContent
                ?? $xpath->query('//*[@id="bookDescription_feature_div"]')->item(0)?->textContent
        );

        $coverNode = $xpath->query('//*[@id="landingImage" or @id="imgBlkFront"]')->item(0);
        $coverUrl = $coverNode instanceof DOMElement
            ? ($coverNode->getAttribute('data-old-hires') ?: $coverNode->getAttribute('src') ?: null)
            : null;
        $offer = $this->amazonPriceAndCurrency($xpath);

        return [
            'title' => $title,
            'cover_url' => $coverUrl ?: null,
            'byline' => $byline,
            'description' => $description,
            'bullets' => $this->amazonDetailBullets($xpath),
            'price' => $offer['price'],
            'currency' => $offer['currency'],
        ];
    }

    /**
     * GitHub issue #137: `#corePrice_feature_div` and even the wider
     * buy-box wrapper around it (`#desktop_qualifiedBuyBox`) were found
     * genuinely *empty* in the real, live static HTML this class actually
     * fetches — Amazon renders the buy-box price client-side via
     * JavaScript now, so the price text this trait originally looked for
     * simply isn't in the server-rendered response at all any more (the
     * markup shape itself, once matched, is confirmed still accurate —
     * this is a template change elsewhere on the page, not a wrong
     * selector). What the static HTML *does* still carry: a hidden
     * `<div class="… twister-plus-buying-options-price-data">{JSON}</div>`
     * seeding that same client-side render, confirmed on a real product
     * page — its JSON holds a `priceAmount` (a plain float, already
     * decimal-parsed, no `"$24.99"`-style text parsing needed at all any
     * more) and a `currencySymbol` (despite the name, a 3-letter ISO code
     * like `"EUR"`, not a `€`/`$` glyph). That `currencySymbol` also
     * disproves this trait's own former assumption that a hardcoded
     * `amazon.com` URL always means USD pricing — the real page checked
     * for #137 actually showed `EUR`, evidently geo-adapted by Amazon
     * independently of the TLD. `currency` is therefore read from this
     * JSON now, never hardcoded.
     *
     * The legacy `#corePrice_feature_div`/`#priceblock_ourprice`/
     * `#priceblock_dealprice` DOM-based extraction (and its accompanying
     * `"$24.99"`-style text parsing) is kept as a fallback for exactly
     * the reason every other field in this trait stays defensive — a
     * page that doesn't happen to carry the JSON blob (a different
     * category, a stale cached response, a future markup change) still
     * gets a chance at a price via the older mechanism, on the (still
     * unverified beyond this one page) assumption that a fallback hit
     * means USD, the only currency ever confirmed reachable that way.
     *
     * @return array{price: ?float, currency: ?string}
     */
    private function amazonPriceAndCurrency(DOMXPath $xpath): array
    {
        $node = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " twister-plus-buying-options-price-data ")]')->item(0);

        if ($node instanceof DOMElement) {
            $offer = $this->firstAmazonJsonOffer(json_decode($node->textContent ?? '', true));

            if ($offer !== null) {
                return [
                    'price' => (float) $offer['priceAmount'],
                    'currency' => is_string($offer['currencySymbol'] ?? null) ? $offer['currencySymbol'] : null,
                ];
            }
        }

        $legacyPriceNode = $xpath->query(
            '//*[@id="corePrice_feature_div"]//span[contains(@class, "a-offscreen")]
             | //*[@id="priceblock_ourprice"]
             | //*[@id="priceblock_dealprice"]'
        )->item(0);
        $price = $this->parseAmazonPrice($this->cleanText($legacyPriceNode?->textContent));

        return ['price' => $price, 'currency' => $price !== null ? 'USD' : null];
    }

    /**
     * Recursively searches a decoded `twister-plus-buying-options-price-
     * data` JSON structure for the first object carrying both
     * `priceAmount` and `currencySymbol` — the real structure nests that
     * object under a top-level key (e.g. `"desktop_buybox_group_1"`)
     * whose exact name looks auto-generated/unstable, so this doesn't
     * depend on it.
     */
    private function firstAmazonJsonOffer(mixed $decoded): ?array
    {
        if (! is_array($decoded)) {
            return null;
        }

        if (isset($decoded['priceAmount'], $decoded['currencySymbol']) && is_numeric($decoded['priceAmount'])) {
            return $decoded;
        }

        foreach ($decoded as $value) {
            $found = $this->firstAmazonJsonOffer($value);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Amazon formats a legacy DOM-extracted price as e.g. "$24.99" or
     * "$1,299.00" — strips the currency symbol/thousands separators and
     * parses the remaining decimal number. Deliberately not locale-aware
     * (no "24,99 €" comma-decimal handling): this path is only reached
     * when amazonPriceAndCurrency() falls back to it, at which point
     * `currency` is assumed to be USD (see that method's own docblock).
     */
    private function parseAmazonPrice(?string $text): ?float
    {
        if ($text === null || ! preg_match('/[\d,]*\d\.\d{2}/', $text, $matches)) {
            return null;
        }

        return (float) str_replace(',', '', $matches[0]);
    }

    /**
     * Amazon has shown product-details rows under several different
     * container ids over the years (detailBullets_feature_div,
     * productDetails_detailBullets_sections1/2, prodDetails' table rows)
     * — every known shape is checked, all of them merged into one
     * label => value map, since only one is normally present on a given
     * page at a time and this trait doesn't need to know which.
     *
     * GitHub issue #141: a real DVD/Blu-ray product page provided by the
     * user (a "clp" video/DVD page for "Ant-Man", B07447J2TS) confirmed a
     * *third* shape, `#productOverview_feature_div` — a compact "product
     * overview" table Amazon renders above the fuller bullet list, each
     * row a `<tr class="… po-{field}">` with a bold label `<span>` and a
     * `po-break-word` value `<span>`. This is specifically where "Genre"
     * turned out to actually live on that page — it was entirely absent
     * from `detailBullets_feature_div`, confirming the label guess
     * (`AmazonDvdBlurayProvider::mapProductPageToCandidate()`'s
     * `amazonBullet($bullets, 'Genre', 'Genres')`) had been looking in a
     * container that genuinely never carries it, not using a wrong label.
     * The page checked was served in German (`html lang="de-de"`, reached
     * via a `/-/de/` URL segment — Amazon's own International Customer
     * Program locale override, not something this trait's own requests
     * ever add), so most of its bullet labels came back German
     * ("Regisseur", "Sprache", "Laufzeit", …) and stayed out of scope
     * here — but "Genre" and "Format" were both still the plain English
     * words even on that German rendering, which is what let this
     * specific merge be added with confidence. Whether this trait's own
     * plain `/dp/{asin}` requests (no `/-/de/` segment, explicit
     * `Accept-Language: en-US`) would ever be served this same localized
     * shape is not itself confirmed either way.
     *
     * @return array<string, string>
     */
    private function amazonDetailBullets(DOMXPath $xpath): array
    {
        $bullets = [];

        // "Product details" bullet list: each <li> wraps a label span and a
        // value span inside an outer container span (`a-list-item`) — the
        // `not(.//span)` predicate selects only *leaf* spans (no further
        // span descendants), i.e. exactly the label/value pair, not that
        // outer wrapper's own concatenated "label: value" text, regardless
        // of how deeply either one happens to be nested.
        foreach ($xpath->query('//*[@id="detailBullets_feature_div"]//li') as $item) {
            $spans = $xpath->query('.//span[not(.//span)]', $item);
            if ($spans->length < 2) {
                continue;
            }
            $label = rtrim($this->cleanText($spans->item(0)?->textContent) ?? '', ": \t\n\r\0\x0B");
            $value = $this->cleanText($spans->item($spans->length - 1)?->textContent);
            if ($label !== '' && $value !== null) {
                $bullets[$label] = $value;
            }
        }

        // "Product information" table (th = label, td = value) — the older/alternate shape.
        foreach ($xpath->query('//*[@id="productDetails_detailBullets_sections1" or @id="productDetails_db_sections"]//tr') as $row) {
            $label = rtrim($this->cleanText($xpath->query('.//th', $row)->item(0)?->textContent) ?? '', ": \t\n\r\0\x0B");
            $value = $this->cleanText($xpath->query('.//td', $row)->item(0)?->textContent);
            if ($label !== '' && $value !== null && ! isset($bullets[$label])) {
                $bullets[$label] = $value;
            }
        }

        // "Product overview" table (GitHub issue #141) — see this method's
        // own docblock. Scoped to the feature container's own id, not a
        // loose `po-` class match, since that prefix is also used on this
        // widget's unrelated expander/truncate controls.
        foreach ($xpath->query('//*[@id="productOverview_feature_div"]//tr[contains(concat(" ", normalize-space(@class), " "), " po-")]') as $row) {
            $labelNode = $xpath->query('.//span[contains(concat(" ", normalize-space(@class), " "), " a-text-bold ")]', $row)->item(0);
            $valueNode = $xpath->query('.//span[contains(concat(" ", normalize-space(@class), " "), " po-break-word ")]', $row)->item(0);
            $label = rtrim($this->cleanText($labelNode?->textContent) ?? '', ": \t\n\r\0\x0B");
            $value = $this->cleanText($valueNode?->textContent);
            if ($label !== '' && $value !== null && ! isset($bullets[$label])) {
                $bullets[$label] = $value;
            }
        }

        return $bullets;
    }

    /** Case-insensitive lookup trying each given label spelling in turn (Amazon isn't perfectly consistent about e.g. "ISBN-13" vs "ISBN13", "Publication date" vs "Release date") — returns the first match, or null if none of the given labels are present. */
    private function amazonBullet(array $bullets, string ...$labels): ?string
    {
        $normalized = [];
        foreach ($bullets as $label => $value) {
            $normalized[mb_strtolower(trim($label))] = $value;
        }

        foreach ($labels as $label) {
            $value = $normalized[mb_strtolower($label)] ?? null;
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * GET with a standard desktop-browser User-Agent (just to receive the
     * normal HTML a browser gets — see this trait's docblock for why that
     * is not the same thing as fingerprint evasion) and en-US content
     * negotiation. Best-effort like every other provider's request() —
     * any non-2xx status, transport failure, or empty body is simply "no
     * result", logged, never thrown; a CAPTCHA/"Robot Check" interstitial
     * page (still HTTP 200) is indistinguishable from a genuine empty
     * result at this layer and deliberately not specially detected —
     * downstream parsing just finds none of the expected elements and
     * returns nulls/empty arrays either way, the same outcome.
     */
    private function amazonGet(string $url, array $query = []): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept-Language' => 'en-US,en;q=0.9',
            ])->timeout(self::TIMEOUT_SECONDS)->get($url, $query);
        } catch (\Throwable $e) {
            Log::info('Amazon scrape request failed.', ['url' => $url, 'error' => $e->getMessage()]);

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
        // Amazon's pages don't validate as strict XML/XHTML, and
        // DOMDocument is vocal about that by default.
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    /**
     * Amazon's detail-bullet labels embed invisible Unicode bidi control
     * characters around the colon (left-to-right/right-to-left marks,
     * U+200E/U+200F — confirmed present in real markup, presumably so the
     * colon renders correctly regardless of the surrounding text's
     * direction) — stripped here alongside the zero-width space/BOM,
     * otherwise a label like "Publisher\u{200F}: \u{200E}" fails to match
     * "Publisher" in amazonBullet()'s lookup despite looking identical to
     * a human reading it.
     */
    private function cleanText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $withoutBidiMarks = preg_replace('/[\x{200B}-\x{200F}\x{FEFF}]/u', '', $text) ?? $text;
        $collapsed = trim(preg_replace('/\s+/u', ' ', $withoutBidiMarks) ?? '');

        return $collapsed === '' ? null : $collapsed;
    }

    /**
     * Amazon detail bullets write dates as free-form English text ("July
     * 1, 2005", sometimes wrapped in a publisher bullet's own parentheses,
     * e.g. "Ace; Reissue edition (July 1, 2005)") — this extracts a
     * parenthesized date if present, otherwise tries to parse the whole
     * string, and returns null (not the raw string) if neither works
     * rather than storing something that isn't actually a valid date.
     */
    private function parseAmazonDate(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        if (preg_match('/\(([^)]+)\)\s*$/', $text, $matches)) {
            $text = $matches[1];
        }

        $timestamp = strtotime($text);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    /** Pulls the leading integer out of a free-text bullet value like "320 pages" or "142 minutes" — null if the value doesn't start with a recognizable number. */
    private function parseLeadingInt(?string $text): ?int
    {
        if ($text !== null && preg_match('/^([\d,]+)/', $text, $matches)) {
            return (int) str_replace(',', '', $matches[1]);
        }

        return null;
    }

    /**
     * A publisher bullet's value is often "{Publisher}; {edition} ({date})"
     * or "{Publisher} ({date})" — this strips the parenthesized date and
     * any "; ... edition" suffix, leaving just the publisher name. Returns
     * the original trimmed string unchanged if it doesn't match that
     * shape, rather than mangling a publisher name that happens not to
     * follow it.
     */
    private function stripPublisherSuffix(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $withoutDate = preg_replace('/\s*\([^)]*\)\s*$/', '', $text) ?? $text;
        $withoutEdition = preg_replace('/;.*$/', '', $withoutDate) ?? $withoutDate;

        return $this->cleanText($withoutEdition) ?? $this->cleanText($text);
    }

    /**
     * GitHub issue #137: a real product page's `#bylineInfo` was found to
     * also embed a "Format: {value}" segment (e.g. "by Frank Herbert
     * (Author) Format: Paperback") inside the very same container as the
     * actual byline — separated only by an icon element with no text of
     * its own, so the two run together in `textContent` with nothing to
     * split on except this label itself. Strips it so `authors`/`artist`
     * don't carry an unrelated format string.
     *
     * GitHub issue #139: a user-reported case put "Format: DVD" under
     * `cast` instead — same underlying contamination, but not
     * independently confirmed live for a DVD/Blu-ray page the way #137's
     * book case was (both attempted live re-checks for #139 were blocked
     * by Amazon, unlike #137's own successful one-off check). Widened
     * defensively from a trailing-only match to anywhere in the string
     * (a DVD's byline/cast text plausibly has more after the format
     * segment, e.g. a cast list, unlike a book's, where it was always
     * last) and applied to the `cast` bullet value too (`amazonBullet()`'s
     * "Actors" result), not just `byline` — since #139 couldn't be
     * confirmed live, this is deliberately a broader, unverified
     * hardening rather than a targeted fix for a confirmed shape.
     * Returns the original trimmed string unchanged if it doesn't match.
     *
     * GitHub issue #173: a real page later confirmed the "Actors" bullet's
     * exact English label and content (see `AmazonDvdBlurayProvider`'s own
     * docblock) — that page showed no "Format:" contamination in it, but
     * that only means it wasn't re-observed there, not that #139's report
     * was wrong; this stripping stays applied to `cast` regardless.
     */
    private function stripAmazonFormatContamination(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $withoutFormat = preg_replace('/\s*Format:\s*\S+/u', '', $text) ?? $text;

        return $this->cleanText($withoutFormat) ?? $this->cleanText($text);
    }
}
