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
 * Beta feature** (see each provider's own `version()`/`name()`), for
 * reasons laid out in full below and in the issue itself.
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
 * - **Not live-verified**: every other provider in this codebase was
 *   confirmed against the real service during development (see e.g.
 *   DiscogsProvider's/MusicBrainzProvider's docblocks). Repeatedly
 *   scraping the real amazon.com purely to develop/verify this class
 *   would itself be exactly the kind of scraping traffic this feature's
 *   own risk profile is about — so, uniquely among this app's providers,
 *   this one is built and tested only against hand-built HTML fixtures
 *   representative of Amazon's historically documented markup, never
 *   against the live site. Expect it to need real-world adjustment.
 *
 * `en-US`/`en-GB` is explicitly requested (Accept-Language) since field
 * extraction matches English label text — a different Amazon locale would
 * need its own label translations, not attempted here.
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
     * @return array<int, array{asin: string, title: ?string, thumbnail_url: ?string}>
     */
    private function amazonSearch(string $query): array
    {
        $html = $this->amazonGet(self::BASE_URL.'/s', ['k' => $query]);

        if ($html === null) {
            return [];
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
     * @return array{title: ?string, cover_url: ?string, byline: ?string, description: ?string, bullets: array<string, string>}|null Null when the page couldn't be fetched/parsed at all (blocked, network failure, ...).
     */
    private function amazonProductPage(string $asin): ?array
    {
        $html = $this->amazonGet(self::BASE_URL."/dp/{$asin}");

        if ($html === null) {
            return null;
        }

        $xpath = $this->xpathFor($html);

        $title = $this->cleanText($xpath->query('//*[@id="productTitle"]')->item(0)?->textContent);
        $byline = $this->cleanText($xpath->query('//*[@id="bylineInfo"]')->item(0)?->textContent);
        $description = $this->cleanText(
            $xpath->query('//*[@id="feature-bullets"]')->item(0)?->textContent
                ?? $xpath->query('//*[@id="productDescription"]')->item(0)?->textContent
        );

        $coverNode = $xpath->query('//*[@id="landingImage" or @id="imgBlkFront"]')->item(0);
        $coverUrl = $coverNode instanceof DOMElement
            ? ($coverNode->getAttribute('data-old-hires') ?: $coverNode->getAttribute('src') ?: null)
            : null;

        return [
            'title' => $title,
            'cover_url' => $coverUrl ?: null,
            'byline' => $byline,
            'description' => $description,
            'bullets' => $this->amazonDetailBullets($xpath),
        ];
    }

    /**
     * Amazon has shown product-details rows under several different
     * container ids over the years (detailBullets_feature_div,
     * productDetails_detailBullets_sections1/2, prodDetails' table rows)
     * — every known shape is checked, all of them merged into one
     * label => value map, since only one is normally present on a given
     * page at a time and this trait doesn't need to know which.
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
}
