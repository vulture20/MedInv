<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Book\ThaliaBookProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ThaliaBookProvider (GitHub issue #129, analogous to Amazon's #50) —
 * Beta. Unlike AmazonBookProviderTest's fixtures (modeled on Amazon's
 * broadly, publicly documented markup), these fixtures combine what was
 * actually confirmed about real thalia.de pages during a one-time,
 * read-only check of already-indexed search snippets (the `<title>` tag
 * format, the `/artikeldetails/` URL shape) with hand-built, opportunistic
 * schema.org JSON-LD — see ThaliaScraping's own docblock for exactly which
 * parts are confirmed vs. best-effort guesses. These fixtures exist to
 * prove the *parsing logic* behaves correctly against a plausible input
 * shape, not to guarantee the real site still looks like this today.
 */
class ThaliaBookProviderTest extends TestCase
{
    private const SEARCH_API = 'https://www.thalia.de/shop/home/suche*';

    private const PRODUCT_API = 'https://www.thalia.de/shop/home/artikeldetails/*';

    private function searchResultHtml(): string
    {
        return <<<'HTML'
            <html><body>
            <div class="result">
              <a href="/shop/home/artikeldetails/buecher-1/ID441013597.html"><img src="https://bilder.thalia.media/dune-thumb.jpg" /></a>
              <a href="/shop/home/artikeldetails/buecher-1/ID441013597.html">Dune (Dune Chronicles, Book 1)</a>
            </div>
            <div class="result">
              <a href="/shop/home/artikeldetails/buecher-1/ID593099325.html">Dune Messiah</a>
            </div>
            </body></html>
            HTML;
    }

    private function productPageHtml(): string
    {
        return <<<'HTML'
            <html><head>
            <title>Dune (Dune Chronicles, Book 1) von Frank Herbert - Taschenbuch - 978-0-441-01359-3 | Thalia</title>
            <script type="application/ld+json">
            {
              "@context": "https://schema.org",
              "@type": "Book",
              "name": "Dune (Dune Chronicles, Book 1)",
              "image": "https://bilder.thalia.media/dune-large.jpg",
              "description": "A stunning blend of adventure and mysticism, environmentalism and politics.",
              "author": {"@type": "Person", "name": "Frank Herbert"},
              "publisher": {"@type": "Organization", "name": "Ace"},
              "inLanguage": "en",
              "numberOfPages": 412,
              "datePublished": "2005-07-01",
              "isbn": "978-0-441-01359-3",
              "offers": {"@type": "Offer", "price": "10.49", "priceCurrency": "EUR"}
            }
            </script>
            </head><body></body></html>
            HTML;
    }

    public function test_lookup_by_code_fetches_the_first_search_result_and_maps_the_full_product_page(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response($this->productPageHtml(), 200),
        ]);

        $candidate = app(ThaliaBookProvider::class)->lookupByCode('9780441013593')[0];

        $this->assertSame('Dune (Dune Chronicles, Book 1)', $candidate->attributes['title']);
        $this->assertSame('Frank Herbert', $candidate->attributes['authors']);
        $this->assertSame('Ace', $candidate->attributes['publisher']);
        $this->assertSame('en', $candidate->attributes['language']);
        $this->assertSame(412, $candidate->attributes['page_count']);
        $this->assertSame('2005-07-01', $candidate->attributes['release_date']);
        $this->assertSame('Taschenbuch', $candidate->attributes['format']);
        $this->assertSame('978-0-441-01359-3', $candidate->attributes['isbn13']);
        $this->assertNull($candidate->attributes['isbn10']);
        $this->assertSame('9780441013593', $candidate->attributes['ean']);
        $this->assertSame(10.49, $candidate->attributes['price']);
        $this->assertSame('EUR', $candidate->attributes['currency']);
        $this->assertStringContainsString('adventure and mysticism', $candidate->attributes['description']);
        $this->assertSame(['https://bilder.thalia.media/dune-large.jpg'], $candidate->coverUrls);
        $this->assertSame('ID441013597', $candidate->sourceId);
    }

    /** Without any JSON-LD block at all, the confirmed <title>-tag format alone still yields title/byline/format/code. */
    public function test_falls_back_to_the_title_tag_alone_when_no_json_ld_is_present(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><head><title>Dune von Frank Herbert - Taschenbuch - 978-0-441-01359-3 | Thalia</title></head><body></body></html>',
                200
            ),
        ]);

        $candidate = app(ThaliaBookProvider::class)->lookupByCode('9780441013593')[0];

        $this->assertSame('Dune', $candidate->attributes['title']);
        $this->assertSame('Frank Herbert', $candidate->attributes['authors']);
        $this->assertSame('Taschenbuch', $candidate->attributes['format']);
        $this->assertSame('978-0-441-01359-3', $candidate->attributes['isbn13']);
        $this->assertNull($candidate->attributes['description']);
        $this->assertSame([], $candidate->coverUrls);
    }

    /** A <title> tag with no " von " segment at all (unexpected shape) degrades to just the cleaned string as title, rather than throwing or discarding it. */
    public function test_a_title_tag_without_a_von_segment_is_used_as_the_bare_title(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response('<html><head><title>Dune | Thalia</title></head><body></body></html>', 200),
        ]);

        $candidate = app(ThaliaBookProvider::class)->lookupByCode('9780441013593')[0];

        $this->assertSame('Dune', $candidate->attributes['title']);
        $this->assertNull($candidate->attributes['authors']);
    }

    public function test_lookup_by_code_requests_the_search_page_with_the_code_as_the_query(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response($this->productPageHtml(), 200),
        ]);

        app(ThaliaBookProvider::class)->lookupByCode('9780441013593');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.thalia.de/shop/home/suche?') && $request['sq'] === '9780441013593');
    }

    public function test_a_desktop_user_agent_is_sent(): void
    {
        Http::fake([self::SEARCH_API => Http::response('<html></html>', 200)]);

        app(ThaliaBookProvider::class)->lookupByCode('9780441013593');

        Http::assertSent(fn ($request) => str_contains($request->header('User-Agent')[0] ?? '', 'Mozilla'));
    }

    /** The two search-result anchors for the same product (image-only + text-only) are merged into one result, not two. */
    public function test_search_result_anchors_for_the_same_product_are_merged(): void
    {
        Http::fake([self::SEARCH_API => Http::response($this->searchResultHtml(), 200)]);

        $candidates = app(ThaliaBookProvider::class)->search('dune');

        $this->assertCount(2, $candidates);
        $this->assertSame('Dune (Dune Chronicles, Book 1)', $candidates[0]->attributes['title']);
        $this->assertSame(['https://bilder.thalia.media/dune-thumb.jpg'], $candidates[0]->coverUrls);
    }

    public function test_falls_back_to_the_search_result_when_the_product_page_fetch_fails(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response('', 503),
        ]);

        $candidate = app(ThaliaBookProvider::class)->lookupByCode('9780441013593')[0];

        $this->assertSame('Dune (Dune Chronicles, Book 1)', $candidate->attributes['title']);
        $this->assertArrayNotHasKey('isbn13', $candidate->attributes);
        $this->assertSame(['https://bilder.thalia.media/dune-thumb.jpg'], $candidate->coverUrls);
    }

    public function test_no_candidates_when_the_search_returns_no_results(): void
    {
        Http::fake([self::SEARCH_API => Http::response('<html><body>No results.</body></html>', 200)]);

        $candidates = app(ThaliaBookProvider::class)->lookupByCode('0000000000000');

        $this->assertSame([], $candidates);
    }

    /** GitHub issue #53's pattern: a blocked/failed request is reported as 'failed', not silently as 'no_match'. */
    public function test_lookup_by_code_throws_when_the_search_request_is_blocked(): void
    {
        Http::fake([self::SEARCH_API => Http::response('', 403)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(ThaliaBookProvider::class)->lookupByCode('9780441013593');
    }

    /** search() (unlike lookupByCode()) deliberately keeps the old silent-empty behavior on a block — see AmazonScraping::amazonSearch()'s docblock, mirrored by ThaliaScraping::thaliaSearch(). */
    public function test_search_returns_no_candidates_when_the_search_request_is_blocked(): void
    {
        Http::fake([self::SEARCH_API => Http::response('', 403)]);

        $candidates = app(ThaliaBookProvider::class)->search('Dune');

        $this->assertSame([], $candidates);
    }

    public function test_search_maps_every_result_without_fetching_individual_product_pages(): void
    {
        Http::fake([self::SEARCH_API => Http::response($this->searchResultHtml(), 200)]);

        $candidates = app(ThaliaBookProvider::class)->search('dune');

        $this->assertCount(2, $candidates);
        $this->assertSame('Dune Messiah', $candidates[1]->attributes['title']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/artikeldetails/'));
    }

    public function test_configuration_requires_no_api_key(): void
    {
        $this->assertSame([], app(ThaliaBookProvider::class)->configFields());
    }

    public function test_name_key_and_version_identify_this_as_the_beta_thalia_provider(): void
    {
        $provider = app(ThaliaBookProvider::class);

        $this->assertSame('Thalia', $provider->name());
        $this->assertSame('book.thalia', $provider->key());
        $this->assertSame('v0.1-beta', $provider->version());
        $this->assertSame('scraping', $provider->sourceType());
    }
}
