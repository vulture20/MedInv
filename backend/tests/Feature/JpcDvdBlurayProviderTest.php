<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\DvdBluray\JpcDvdBlurayProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** JpcDvdBlurayProvider (GitHub issue #130) — see JpcCdProviderTest's docblock for the fixture-based testing approach and why. */
class JpcDvdBlurayProviderTest extends TestCase
{
    private const SEARCH_API = 'https://www.jpc.de/jpcng/home/search*';

    private const PRODUCT_API = 'https://www.jpc.de/jpcng/*/detail/-/art/*';

    private function searchResultHtml(): string
    {
        return <<<'HTML'
            <html><body>
            <a href="/jpcng/movie/detail/-/art/das-wandelnde-schloss-bd-dvd/hnum/6171414">Das wandelnde Schloss</a>
            </body></html>
            HTML;
    }

    /**
     * Trimmed down from real, byte-exact jpc.de HTML fetched for GitHub
     * issue #135 — every label here is wrapped in `<b>` (the actual real
     * shape on the DVD/Blu-ray page checked, unlike the CD page's mix of
     * wrapped and bare labels), which the pre-#135 version of
     * jpcDetailValue() failed to resolve entirely — see that method's own
     * docblock.
     */
    private function productPageHtml(): string
    {
        return <<<'HTML'
            <html><head>
            <title>Das wandelnde Schloss (Blu-ray & DVD im Steelbook) – jpc.de</title>
            </head><body>
            <span itemprop="offers" itemscope itemtype="https://schema.org/Offer">
                <meta itemprop="price" content="29.99"/>
                <meta itemprop="priceCurrency" content="EUR"/>
            </span>
            <dl>
              <dt><b>Herkunftsland:</b></dt><dd>Japan, 2004</dd>
              <dt><b>UPC/EAN:</b></dt><dd><span itemprop="productID">0889853970292</span></dd>
              <dt><b>Erscheinungstermin:</b></dt><dd>5.5.2017</dd>
              <dt><b>Spieldauer ca.:</b></dt><dd>119 Min.</dd>
              <dt><b>Regie:</b></dt><dd>Hayao Miyazaki</dd>
              <dt><b>Darsteller:</b></dt><dd><a href="/s/chieko+baisho">Chieko Baisho</a>, <a href="/s/takuya+kimura">Takuya Kimura</a></dd>
              <dt><b>Genre:</b></dt><dd>Anime</dd>
              <dt><b>Sprache:</b></dt><dd>Deutsch, Japanisch</dd>
              <dt><b>Untertitel:</b></dt><dd>Deutsch</dd>
            </dl>
            <div class="box content textlink" id="red-text">
                <button aria-controls="primaryTextBlock-6171414">Weiterlesen</button>
                <div class="product-video-preview"><h3>Filmausschnitte/Videotrailer</h3><p>Nicht die Beschreibung.</p></div>
                <div data-pd="j"><div class="collapsable is-collapsed">
                    <p>Sophie wird von einer Hexe in eine alte Frau verwandelt. (Filmstarts.de)</p>
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

        $candidate = app(JpcDvdBlurayProvider::class)->lookupByCode('0889853970292')[0];

        $this->assertSame('Das wandelnde Schloss', $candidate->attributes['title']);
        $this->assertSame('Blu-ray & DVD im Steelbook', $candidate->attributes['medium']);
        // GitHub issue #136: no leading number in the format string here, so disc_count stays null (falls back to the DB's own default of 1) rather than a wrong guess.
        $this->assertNull($candidate->attributes['disc_count']);
        $this->assertSame(119, $candidate->attributes['runtime_minutes']);
        $this->assertSame('Hayao Miyazaki', $candidate->attributes['director']);
        $this->assertSame('Deutsch, Japanisch', $candidate->attributes['languages']);
        // GitHub issue #140.
        $this->assertSame('Anime', $candidate->attributes['genre']);
        $this->assertSame('Deutsch', $candidate->attributes['subtitles']);
        $this->assertSame('2017-05-05', $candidate->attributes['release_date']);
        $this->assertSame(2017, $candidate->attributes['production_year']);
        $this->assertSame('0889853970292', $candidate->attributes['ean']);
        // GitHub issue #135: cover URL derivation depends on EAN extraction, which the <b>-wrapped-label bug silently broke — this is the "cover is missing" bug report, now fixed. Also now w2400 (full resolution), not w468.
        $this->assertSame(['https://media1.jpc.de/image/w2400/front/0/0889853970292.jpg'], $candidate->coverUrls);
        $this->assertSame('6171414', $candidate->sourceId);
        // GitHub issue #135: price/currency now extracted via confirmed schema.org Microdata.
        $this->assertSame(29.99, $candidate->attributes['price']);
        $this->assertSame('EUR', $candidate->attributes['currency']);
        // GitHub issue #213: confirmed real "Darsteller:" label, same
        // <dt>/<dd> sibling shape as "Regie:" above — the sibling walk
        // concatenates every <a> plus the ", " text nodes between them
        // into one clean, comma-separated name list.
        $this->assertSame('Chieko Baisho, Takuya Kimura', $candidate->attributes['cast']);
        // GitHub issue #214: extracted from the "Weiterlesen" collapsible
        // box (#red-text) — see JpcScraping::jpcDescription()'s docblock.
        // The trailing "(Filmstarts.de)" source note is kept, not
        // stripped, and the unrelated video-preview <p> right next to it
        // within #red-text is correctly ignored (see the dedicated
        // scoping test below).
        $this->assertSame('Sophie wird von einer Hexe in eine alte Frau verwandelt. (Filmstarts.de)', $candidate->attributes['description']);
    }

    /**
     * GitHub issue #214: `#red-text` also holds unrelated content on a
     * real page (a video-trailer preview, a translation-language
     * selector, related-edition cards) — jpcDescription() must only ever
     * read the `.collapsable` block the "Weiterlesen" button actually
     * expands/collapses, never anything else nested under that same
     * outer container.
     */
    public function test_description_ignores_unrelated_content_inside_red_text(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><head><title>Some Film (DVD) – jpc.de</title></head><body>'
                .'<div class="box content textlink" id="red-text">'
                .'<div class="product-video-preview"><h3>Videotrailer</h3><p>Nicht die Beschreibung.</p></div>'
                .'<div data-pd="j"><div class="collapsable is-collapsed"><p>Die echte Beschreibung.</p></div></div>'
                .'</div>'
                .'</body></html>',
                200
            ),
        ]);

        $candidate = app(JpcDvdBlurayProvider::class)->lookupByCode('0000000000000')[0];

        $this->assertSame('Die echte Beschreibung.', $candidate->attributes['description']);
    }

    /** GitHub issue #136: jpc.de has no dedicated disc-count label at all — confirmed live on "Hogfather (Special Edition) (2 DVDs) – jpc.de" (EAN 4009750242353), the exact real title tag the reporting user's example resolved to. GitHub issue #138: "medium" no longer redundantly repeats the count disc_count already carries — "2 DVDs" becomes just "DVD". */
    public function test_a_multi_disc_release_derives_disc_count_from_the_format_string(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><head><title>Hogfather (Special Edition) (2 DVDs) – jpc.de</title></head><body></body></html>',
                200
            ),
        ]);

        $candidate = app(JpcDvdBlurayProvider::class)->lookupByCode('4009750242353')[0];

        $this->assertSame('Hogfather (Special Edition)', $candidate->attributes['title']);
        $this->assertSame('DVD', $candidate->attributes['medium']);
        $this->assertSame(2, $candidate->attributes['disc_count']);
    }

    /** GitHub issue #143: confirmed live on "Fringe Season 5" (EAN 5051890205261, a 5-DVD TV-season box set) — the Genre row there reads "Thriller, (13 Episoden)", not just "Thriller". */
    public function test_genre_strips_a_trailing_episode_count_annotation(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><head><title>Fringe Season 5 (5 DVDs) – jpc.de</title></head><body>'
                .'<dl><dt><b>Genre:</b></dt><dd><a href="/s/Thriller?searchtype=zeile2">Thriller,</a> (13 Episoden)</dd></dl>'
                .'</body></html>',
                200
            ),
        ]);

        $candidate = app(JpcDvdBlurayProvider::class)->lookupByCode('5051890205261')[0];

        $this->assertSame('Thriller', $candidate->attributes['genre']);
    }

    /** GitHub issue #138: an explicit "1 DVD" (not confirmed live, but a plausible shape) strips down to "DVD" too — no trailing "s" to remove since "DVD" was never pluralized in the first place, unlike "2 DVDs". */
    public function test_an_explicit_single_disc_count_also_strips_the_leading_number(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><head><title>Some Film (1 DVD) – jpc.de</title></head><body></body></html>',
                200
            ),
        ]);

        $candidate = app(JpcDvdBlurayProvider::class)->lookupByCode('0000000000000')[0];

        $this->assertSame('DVD', $candidate->attributes['medium']);
        $this->assertSame(1, $candidate->attributes['disc_count']);
    }

    public function test_falls_back_to_the_search_result_when_the_product_page_fetch_fails(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response('', 503),
        ]);

        $candidate = app(JpcDvdBlurayProvider::class)->lookupByCode('0889853970292')[0];

        $this->assertSame('Das wandelnde Schloss', $candidate->attributes['title']);
        $this->assertArrayNotHasKey('runtime_minutes', $candidate->attributes);
        $this->assertSame([], $candidate->coverUrls);
    }

    public function test_search_maps_every_result_without_fetching_individual_product_pages(): void
    {
        Http::fake([self::SEARCH_API => Http::response($this->searchResultHtml(), 200)]);

        $candidates = app(JpcDvdBlurayProvider::class)->search('wandelnde schloss');

        $this->assertCount(1, $candidates);
        $this->assertSame('Das wandelnde Schloss', $candidates[0]->attributes['title']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/detail/-/art/'));
    }

    /** GitHub issue #53's pattern: a blocked/failed request is reported as 'failed', not silently as 'no_match'. */
    public function test_lookup_by_code_throws_when_the_search_request_is_blocked(): void
    {
        Http::fake([self::SEARCH_API => Http::response('', 503)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(JpcDvdBlurayProvider::class)->lookupByCode('0000000000000');
    }

    /** GitHub issue #145: no longer Beta. */
    public function test_name_and_version_have_no_redundant_beta_suffix(): void
    {
        $provider = app(JpcDvdBlurayProvider::class);

        $this->assertSame('JPC', $provider->name());
        $this->assertSame('v1.0', $provider->version());
    }
}
