<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Book\JpcBookProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * JpcBookProvider (GitHub issue #131 — #130's original JPC implementation
 * wrongly assumed JPC doesn't sell books). See JpcCdProviderTest's
 * docblock for the fixture-based testing approach and why; these
 * fixtures additionally exercise the book-only `{Titel} - {Autor}`
 * title-tag convention (the reverse order and a different separator from
 * CD's `{Artist}: {Titel}`) confirmed on a real jpc.de book page.
 */
class JpcBookProviderTest extends TestCase
{
    private const SEARCH_API = 'https://www.jpc.de/jpcng/home/search*';

    private const PRODUCT_API = 'https://www.jpc.de/jpcng/*/detail/-/art/*';

    private function searchResultHtml(): string
    {
        return <<<'HTML'
            <html><body>
            <a href="/jpcng/books/detail/-/art/mariana-leky-kummer-aller-art/hnum/10927986">Kummer aller Art - Mariana Leky</a>
            </body></html>
            HTML;
    }

    /**
     * Trimmed down from real, byte-exact jpc.de HTML fetched for GitHub
     * issue #135 — deliberately keeps the real inconsistency (a bare
     * `<dt>Verlag:</dt>` alongside every other label wrapped in `<b>`,
     * e.g. `<dt><b>Einband:</b></dt>`) and the real price Microdata, same
     * reasoning as JpcCdProviderTest's own fixture.
     */
    private function productPageHtml(): string
    {
        return <<<'HTML'
            <html><head>
            <title>Kummer aller Art - Mariana Leky (Buch) – jpc.de</title>
            </head><body>
            <span itemprop="offers" itemscope itemtype="https://schema.org/Offer">
                <meta itemprop="price" content="22.00"/>
                <meta itemprop="priceCurrency" content="EUR"/>
            </span>
            <dl>
                <dt>Verlag:</dt>
                <dd>DuMont Buchverlag GmbH, 07/2022</dd>
                <dt><b>Einband:</b></dt>
                <dd>Gebunden</dd>
                <dt><b>Sprache:</b></dt>
                <dd>Deutsch</dd>
                <dt><b>ISBN-13:</b></dt>
                <dd><span itemprop="productID">9783832182168</span></dd>
                <dt><b>Artikelnummer:</b></dt>
                <dd><span id="hnum" itemprop="sku">10927986</span></dd>
                <dt><b>Umfang:</b></dt>
                <dd>176 Seiten</dd>
                <dt><b>Erscheinungstermin:</b></dt>
                <dd>19.7.2022</dd>
            </dl>
            <div class="box content textlink" id="red-text">
                <button aria-controls="primaryTextBlock-10927986">Weiterlesen</button>
                <div class="product-video-preview"><h3>Irrelevant</h3></div>
                <div data-pd="j"><div class="collapsable is-collapsed">
                    <p>Eine Frau erzählt die Geschichte ihres Lebens. (Verlagstext)</p>
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

        $candidate = app(JpcBookProvider::class)->lookupByCode('9783832182168')[0];

        $this->assertSame('Kummer aller Art', $candidate->attributes['title']);
        $this->assertSame('Mariana Leky', $candidate->attributes['authors']);
        $this->assertSame('DuMont Buchverlag GmbH', $candidate->attributes['publisher']);
        $this->assertSame('Deutsch', $candidate->attributes['language']);
        $this->assertSame(176, $candidate->attributes['page_count']);
        $this->assertSame('2022-07-19', $candidate->attributes['release_date']);
        $this->assertSame('Gebunden', $candidate->attributes['format']);
        $this->assertSame('9783832182168', $candidate->attributes['isbn13']);
        $this->assertNull($candidate->attributes['isbn10']);
        $this->assertSame('9783832182168', $candidate->attributes['ean']);
        // GitHub issue #135: cover URL derivation depends on ISBN-13 extraction, which the <b>-wrapped-label bug silently broke for most labels — this is the "cover is missing" bug report, now fixed. Also now w2400 (full resolution), not w468.
        $this->assertSame(['https://media1.jpc.de/image/w2400/front/0/9783832182168.jpg'], $candidate->coverUrls);
        $this->assertSame('10927986', $candidate->sourceId);
        // GitHub issue #135: price/currency now extracted via confirmed schema.org Microdata.
        $this->assertSame(22.00, $candidate->attributes['price']);
        $this->assertSame('EUR', $candidate->attributes['currency']);
        // GitHub issue #214: extracted from the "Weiterlesen" collapsible
        // box (#red-text) — see JpcScraping::jpcDescription()'s docblock.
        // The trailing "(Verlagstext)" source note is kept, not stripped.
        $this->assertSame('Eine Frau erzählt die Geschichte ihres Lebens. (Verlagstext)', $candidate->attributes['description']);
    }

    /** A book title tag has no confirmed byline-signal in the title tag other than the trailing " - {Autor}" segment — since a title itself could legitimately contain " - ", this asserts the *last* occurrence is what's split on, not the first. */
    public function test_a_title_containing_its_own_hyphen_splits_on_the_last_occurrence(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><head><title>Vor dem Sturm - Ein Roman - Max Mustermann (Buch) – jpc.de</title></head><body></body></html>',
                200
            ),
        ]);

        $candidate = app(JpcBookProvider::class)->lookupByCode('9783832182168')[0];

        $this->assertSame('Vor dem Sturm - Ein Roman', $candidate->attributes['title']);
        $this->assertSame('Max Mustermann', $candidate->attributes['authors']);
    }

    public function test_falls_back_to_the_search_result_when_the_product_page_fetch_fails(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response('', 503),
        ]);

        $candidate = app(JpcBookProvider::class)->lookupByCode('9783832182168')[0];

        $this->assertSame('Kummer aller Art - Mariana Leky', $candidate->attributes['title']);
        $this->assertArrayNotHasKey('format', $candidate->attributes);
    }

    public function test_search_maps_every_result_without_fetching_individual_product_pages(): void
    {
        Http::fake([self::SEARCH_API => Http::response($this->searchResultHtml(), 200)]);

        $candidates = app(JpcBookProvider::class)->search('mariana leky');

        $this->assertCount(1, $candidates);
        $this->assertSame('Kummer aller Art - Mariana Leky', $candidates[0]->attributes['title']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/detail/-/art/'));
    }

    public function test_no_candidates_when_the_search_returns_no_results(): void
    {
        Http::fake([self::SEARCH_API => Http::response('<html><body>No results.</body></html>', 200)]);

        $this->assertSame([], app(JpcBookProvider::class)->lookupByCode('0000000000000'));
    }

    /** GitHub issue #53's pattern: a blocked/failed request is reported as 'failed', not silently as 'no_match'. */
    public function test_lookup_by_code_throws_when_the_search_request_is_blocked(): void
    {
        Http::fake([self::SEARCH_API => Http::response('', 503)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(JpcBookProvider::class)->lookupByCode('0000000000000');
    }

    public function test_configuration_requires_no_api_key(): void
    {
        $this->assertSame([], app(JpcBookProvider::class)->configFields());
    }

    /** GitHub issue #145: no longer Beta. */
    public function test_name_key_and_version_identify_this_as_the_jpc_provider(): void
    {
        $provider = app(JpcBookProvider::class);

        $this->assertSame('JPC', $provider->name());
        $this->assertSame('book.jpc', $provider->key());
        $this->assertSame('v1.0', $provider->version());
        $this->assertSame('scraping', $provider->sourceType());
    }
}
