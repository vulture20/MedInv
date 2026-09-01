<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Cd\JpcCdProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * JpcCdProvider (GitHub issue #130, analogous to Amazon's #50). jpc.de did
 * not block a direct fetch during development, unlike thalia.de (a
 * since-removed provider — GitHub issue #134, thalia.de turned out to be
 * permanently blocked by Cloudflare bot-management) — but the *research
 * tool* used for #130/#131's original implementation converted pages to
 * Markdown before answering questions about them, which silently
 * misrepresented DOM structure (see GitHub issue #135, JpcScraping's own
 * docblock, and jpcDetailValue()'s own docblock for the real bug this
 * caused in production). These fixtures were rebuilt from byte-exact HTML
 * fetched directly (`curl`, no Markdown conversion) for #135 — including
 * the real, inconsistent `<dt>`/`<dt><b>` label wrapping and the real
 * schema.org Microdata (price, track listing) jpc.de actually embeds —
 * not to guarantee the real site still looks like this today, but to
 * actually exercise the parsing logic against a *confirmed* real shape
 * this time, not a reconstructed one.
 */
class JpcCdProviderTest extends TestCase
{
    private const SEARCH_API = 'https://www.jpc.de/jpcng/home/search*';

    private const PRODUCT_API = 'https://www.jpc.de/jpcng/*/detail/-/art/*';

    private function searchResultHtml(): string
    {
        return <<<'HTML'
            <html><body>
            <div class="result">
              <a href="/jpcng/poprock/detail/-/art/mark-medlock-dsds-back-into-the-sun/hnum/12765025"><img src="https://media1.jpc.de/image/w98/front/0/4029759218739.jpg" /></a>
              <a href="/jpcng/poprock/detail/-/art/mark-medlock-dsds-back-into-the-sun/hnum/12765025">Mark Medlock (DSDS): Back Into The Sun</a>
            </div>
            </body></html>
            HTML;
    }

    /**
     * Trimmed down from real, byte-exact jpc.de HTML fetched for GitHub
     * issue #135 — deliberately keeps the real inconsistency (a bare
     * `<dt>Label:</dt>` alongside `<dt><b>UPC/EAN:</b></dt>`) and the real
     * schema.org Microdata shape (price, track listing) rather than a
     * cleaned-up version, since that inconsistency is exactly what #135's
     * fix needs to handle correctly.
     */
    private function productPageHtml(): string
    {
        return <<<'HTML'
            <html><head>
            <title>Mark Medlock (DSDS): Back Into The Sun (CD) – jpc.de</title>
            </head><body>
            <span itemprop="offers" itemscope itemtype="https://schema.org/Offer">
                <meta itemprop="price" content="18.99"/>
                <meta itemprop="priceCurrency" content="EUR"/>
            </span>
            <dl class="textlink">
                <dt>Label:</dt>
                <dd><a href="/s/Stars+by+Edel?searchtype=label" class="search-link textlink">Stars by Edel</a></dd>
                <dt><b>Aufnahmejahr ca.:</b></dt>
                <dd>2026</dd>
                <dt><b>Artikelnummer:</b></dt>
                <dd><span id="hnum" itemprop="sku">12765025</span></dd>
                <dt><b>UPC/EAN:</b></dt>
                <dd><span itemprop="productID">4029759218739</span></dd>
                <dt><b>Erscheinungstermin:</b></dt>
                <dd>14.8.2026</dd>
            </dl>
            <li itemscope itemtype="https://schema.org/MusicRecording" itemprop="track" class="odd">
                <meta content="Back Into The Sun" itemprop="inAlbum" />
                <div class="tracks">
                    <b>1</b>
                    <span><span itemprop="name">Back Into The Sun</span></span>
                </div>
            </li>
            <li itemscope itemtype="https://schema.org/MusicRecording" itemprop="track" class="even">
                <meta content="Back Into The Sun" itemprop="inAlbum" />
                <div class="tracks">
                    <b>2</b>
                    <span><span itemprop="name">Mamacita (New Version)</span></span>
                </div>
            </li>
            <tr itemprop="isSimilarTo" itemscope itemtype="https://schema.org/Product">
                <span class="offers" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
                    <meta itemprop="priceCurrency" content="EUR" />
                    <meta itemprop="price" content="36.99" />
                </span>
            </tr>
            <div class="box medium">
                <span class="open-help-layer" data-layer=".help-layer-medium">
                    CD
                </span>
                <button class="open-help-layer" data-layer=".help-layer-medium" aria-label="Hinweis zum Medium"></button>
            </div>
            <div class="box content textlink" id="red-text">
                <button aria-controls="primaryTextBlock-12765025">Weiterlesen</button>
                <div class="product-video-preview"><h3>Irrelevant</h3></div>
                <div data-pd="j"><div class="collapsable is-collapsed">
                    <p>Das neue Album von Mark Medlock.</p><p>Mit Hits wie »Mamacita«. (Label-Info)</p>
                </div></div>
            </div>
            </body></html>
            HTML;
    }

    public function test_lookup_by_code_fetches_the_first_search_result_and_maps_the_full_product_page(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response($this->productPageHtml(), 200),
        ]);

        $candidate = app(JpcCdProvider::class)->lookupByCode('4029759218739')[0];

        $this->assertSame('Back Into The Sun', $candidate->attributes['title']);
        $this->assertSame('Mark Medlock (DSDS)', $candidate->attributes['artist']);
        // GitHub issue #215: sourced from the "box medium" span here (not
        // the <title> tag), which agrees with the title-tag value on this
        // fixture — proves no regression when both sources agree.
        $this->assertSame('CD', $candidate->attributes['medium']);
        // GitHub issue #136: no leading number in the format string here, so disc_count stays null (falls back to the DB's own default of 1) rather than a wrong guess.
        $this->assertNull($candidate->attributes['disc_count']);
        $this->assertSame('2026-08-14', $candidate->attributes['release_date']);
        $this->assertSame('4029759218739', $candidate->attributes['ean']);
        // GitHub issue #135: cover URL derivation depends on EAN extraction, which the <b>-wrapped-label bug silently broke — this is the "cover is missing" bug report, now fixed. Also now w2400 (full resolution), not w468.
        $this->assertSame(['https://media1.jpc.de/image/w2400/front/0/4029759218739.jpg'], $candidate->coverUrls);
        $this->assertSame('12765025', $candidate->sourceId);
        // GitHub issue #135: price/currency and tracks are now extracted via confirmed schema.org Microdata.
        $this->assertSame(18.99, $candidate->attributes['price']);
        $this->assertSame('EUR', $candidate->attributes['currency']);
        $this->assertCount(2, $candidate->attributes['tracks']);
        $this->assertSame('1', $candidate->attributes['tracks'][0]['position']);
        $this->assertSame('Back Into The Sun', $candidate->attributes['tracks'][0]['title']);
        $this->assertNull($candidate->attributes['tracks'][0]['duration_seconds']);
        $this->assertSame('Mamacita (New Version)', $candidate->attributes['tracks'][1]['title']);
        // GitHub issue #214/#216: extracted from the "Weiterlesen"
        // collapsible box (#red-text) — see JpcScraping::jpcDescription()'s
        // docblock. The trailing "(Label-Info)" source note is kept, not
        // stripped. GitHub issue #216: confirmed live on a real CD page
        // that adjacent <p> tags with no whitespace between them in the
        // raw HTML must still come out space-separated, not glued
        // together ("...Medlock.Mit Hits...").
        $this->assertSame('Das neue Album von Mark Medlock. Mit Hits wie »Mamacita«. (Label-Info)', $candidate->attributes['description']);
    }

    /** GitHub issue #136: jpc.de has no dedicated disc-count label at all — confirmed live on "Pink Floyd: The Wall (remastered) (180g) (2 LPs) – jpc.de", a real multi-disc vinyl release, including that the earlier, unrelated "(180g)" parenthesized segment must not be mistaken for the format/disc-count one. GitHub issue #138: "medium" no longer redundantly repeats the count disc_count already carries — "2 LPs" becomes just "LP". */
    public function test_a_multi_disc_release_derives_disc_count_from_the_format_string(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><head><title>Pink Floyd: The Wall (remastered) (180g) (2 LPs) – jpc.de</title></head><body></body></html>',
                200
            ),
        ]);

        $candidate = app(JpcCdProvider::class)->lookupByCode('0602488213288')[0];

        $this->assertSame('Pink Floyd', $candidate->attributes['artist']);
        $this->assertSame('The Wall (remastered) (180g)', $candidate->attributes['title']);
        $this->assertSame('LP', $candidate->attributes['medium']);
        $this->assertSame(2, $candidate->attributes['disc_count']);
    }

    /**
     * GitHub issue #215: not independently confirmed live on a real CD
     * page (see JpcScraping::jpcMediumSpanText()'s own docblock — used
     * here anyway on the "generic, non-media-type-specific container"
     * reasoning already applied to `#red-text`). Models a case the
     * <title>-tag-only approach could never handle at all: a format
     * segment with no leading digit of its own (e.g. a boxed set titled
     * generically), where the "box medium" span is the only source with
     * the actual count.
     */
    public function test_disc_count_prefers_the_medium_span_when_present(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><head><title>Pink Floyd: The Wall (Box Set) – jpc.de</title></head><body>'
                .'<div class="box medium"><span class="open-help-layer" data-layer=".help-layer-medium">'
                ."\n                                    3\n                        LPs\n            "
                .'</span></div>'
                .'</body></html>',
                200
            ),
        ]);

        $candidate = app(JpcCdProvider::class)->lookupByCode('0602488213288')[0];

        $this->assertSame('LP', $candidate->attributes['medium']);
        $this->assertSame(3, $candidate->attributes['disc_count']);
    }

    /**
     * GitHub issue #144: a research check (not a byte-exact `curl` fetch —
     * see this class's own docblock and JpcScraping::
     * stripJpcTrackPreviewAnnotation()'s docblock for why that
     * distinction matters here) found a "Hörprobe" preview-control label
     * repeating the track title on two real, distinct CD pages. Whether
     * that text genuinely sits inside the same `itemprop="name"` element
     * this fixture models could not be confirmed, so this is deliberately
     * a defensive, unverified-shape test — the same treatment
     * AmazonDvdBlurayProvider's own `cast` field got for GitHub issue
     * #139, before that field was removed entirely by GitHub issue #150.
     */
    public function test_track_titles_strip_a_hoerprobe_preview_annotation(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><head><title>Adele: 30 (CD) – jpc.de</title></head><body>'
                .'<li itemscope itemtype="https://schema.org/MusicRecording" itemprop="track">'
                .'<div class="tracks"><b>1</b><span><span itemprop="name">Strangers by nature Hörprobe Track 1: Strangers by nature</span></span></div>'
                .'</li>'
                .'</body></html>',
                200
            ),
        ]);

        $candidate = app(JpcCdProvider::class)->lookupByCode('0602438571051')[0];

        $this->assertSame('Strangers by nature', $candidate->attributes['tracks'][0]['title']);
    }

    /**
     * GitHub issue #146: absoluteJpcUrl() must never follow an absolute
     * href pointing off jpc.de — jpcProductPage() fetches that URL
     * server-side, so an unrestricted absolute href would be a
     * server-side-request-forgery primitive. The malicious result here
     * sorts first in document order, so if the host check were missing,
     * lookupByCode() would fetch the attacker's URL instead of (or as
     * well as) the real jpc.de one.
     */
    public function test_search_ignores_an_absolute_href_pointing_off_jpc_de(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response(
                '<html><body>'
                .'<a href="https://evil.example.com/detail/-/art/malicious/hnum/1"><img src="https://evil.example.com/x.jpg" /></a>'
                .'<a href="https://evil.example.com/detail/-/art/malicious/hnum/1">Malicious External Result</a>'
                .'<div class="result">'
                .'<a href="/jpcng/poprock/detail/-/art/mark-medlock-dsds-back-into-the-sun/hnum/12765025"><img src="https://media1.jpc.de/image/w98/front/0/4029759218739.jpg" /></a>'
                .'<a href="/jpcng/poprock/detail/-/art/mark-medlock-dsds-back-into-the-sun/hnum/12765025">Mark Medlock (DSDS): Back Into The Sun</a>'
                .'</div>'
                .'</body></html>',
                200
            ),
            self::PRODUCT_API => Http::response($this->productPageHtml(), 200),
        ]);

        $candidate = app(JpcCdProvider::class)->lookupByCode('4029759218739')[0];

        $this->assertSame('Back Into The Sun', $candidate->attributes['title']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example.com'));
    }

    public function test_falls_back_to_the_search_result_when_the_product_page_fetch_fails(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response('', 503),
        ]);

        $candidate = app(JpcCdProvider::class)->lookupByCode('4029759218739')[0];

        $this->assertSame('Mark Medlock (DSDS): Back Into The Sun', $candidate->attributes['title']);
        $this->assertArrayNotHasKey('medium', $candidate->attributes);
        $this->assertSame(['https://media1.jpc.de/image/w98/front/0/4029759218739.jpg'], $candidate->coverUrls);
    }

    public function test_search_maps_every_result_without_fetching_individual_product_pages(): void
    {
        Http::fake([self::SEARCH_API => Http::response($this->searchResultHtml(), 200)]);

        $candidates = app(JpcCdProvider::class)->search('mark medlock');

        $this->assertCount(1, $candidates);
        $this->assertSame('Mark Medlock (DSDS): Back Into The Sun', $candidates[0]->attributes['title']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/detail/-/art/'));
    }

    public function test_no_candidates_when_the_search_returns_no_results(): void
    {
        Http::fake([self::SEARCH_API => Http::response('<html><body>No results.</body></html>', 200)]);

        $this->assertSame([], app(JpcCdProvider::class)->lookupByCode('0000000000000'));
    }

    /** GitHub issue #53's pattern: a blocked/failed request is reported as 'failed', not silently as 'no_match'. */
    public function test_lookup_by_code_throws_when_the_search_request_is_blocked(): void
    {
        Http::fake([self::SEARCH_API => Http::response('', 503)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(JpcCdProvider::class)->lookupByCode('0000000000000');
    }

    public function test_a_desktop_user_agent_is_sent(): void
    {
        Http::fake([self::SEARCH_API => Http::response('<html></html>', 200)]);

        app(JpcCdProvider::class)->lookupByCode('4029759218739');

        Http::assertSent(fn ($request) => str_contains($request->header('User-Agent')[0] ?? '', 'Mozilla'));
    }

    /** GitHub issue #133: the search request goes to the confirmed real endpoint/parameter (/jpcng/home/search?fastsearch=...), not the original unverified guess (/jpcng/search?searchtext=..., a path that turned out not to exist at all) — see JpcScraping::SEARCH_PATH's own docblock. */
    public function test_the_search_request_uses_the_confirmed_endpoint_and_parameter(): void
    {
        Http::fake([self::SEARCH_API => Http::response('<html></html>', 200)]);

        app(JpcCdProvider::class)->lookupByCode('4029759218739');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.jpc.de/jpcng/home/search?') && $request['fastsearch'] === '4029759218739');
    }

    public function test_configuration_requires_no_api_key(): void
    {
        $this->assertSame([], app(JpcCdProvider::class)->configFields());
    }

    /** GitHub issue #145: no longer Beta. */
    public function test_name_key_and_version_identify_this_as_the_jpc_provider(): void
    {
        $provider = app(JpcCdProvider::class);

        $this->assertSame('JPC', $provider->name());
        $this->assertSame('cd.jpc', $provider->key());
        $this->assertSame('v1.0', $provider->version());
        $this->assertSame('scraping', $provider->sourceType());
    }
}
