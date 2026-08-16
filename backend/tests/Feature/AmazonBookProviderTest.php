<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Book\AmazonBookProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AmazonBookProvider (briefing 8.2, GitHub issue #50) — Beta, see
 * AmazonScraping's docblock for why this couldn't be live-verified against
 * the real amazon.com the way every other provider in this codebase was.
 * Fixtures below are hand-built HTML modeled on Amazon's historically
 * documented product-page structure (confirmed representative via general
 * knowledge of that markup, not a live fetch) — they exist to prove the
 * *parsing logic* behaves correctly against a plausible input shape, not
 * to guarantee the real site still looks like this today.
 */
class AmazonBookProviderTest extends TestCase
{
    private const SEARCH_API = 'https://www.amazon.com/s*';

    private const PRODUCT_API = 'https://www.amazon.com/dp/*';

    private function searchResultHtml(): string
    {
        return <<<'HTML'
            <html><body>
            <div data-component-type="s-search-result" data-asin="0441013597">
              <h2><a class="a-link-normal"><span>Dune (Dune Chronicles, Book 1)</span></a></h2>
              <img class="s-image" src="https://m.media-amazon.com/images/I/dune-thumb.jpg" />
            </div>
            <div data-component-type="s-search-result" data-asin="0593099325">
              <h2><a class="a-link-normal"><span>Dune Messiah</span></a></h2>
              <img class="s-image" src="https://m.media-amazon.com/images/I/messiah-thumb.jpg" />
            </div>
            </body></html>
            HTML;
    }

    private function productPageHtml(): string
    {
        return <<<'HTML'
            <html><body>
            <span id="productTitle"> Dune (Dune Chronicles, Book 1) </span>
            <div id="bylineInfo"><a class="author">Frank Herbert</a> (Author)</div>
            <img id="landingImage" src="https://m.media-amazon.com/images/I/dune-small.jpg" data-old-hires="https://m.media-amazon.com/images/I/dune-large.jpg" />
            <div id="feature-bullets"><ul><li>A stunning blend of adventure and mysticism, environmentalism and politics.</li></ul></div>
            <div id="corePrice_feature_div"><span class="a-price"><span class="a-offscreen">$10.49</span><span aria-hidden="true">$10<span>49</span></span></span></div>
            <div id="detailBullets_feature_div">
              <ul>
                <li><span class="a-list-item"><span class="a-text-bold">Publisher &rlm;: &lrm;</span><span>Ace; Reissue edition (July 1, 2005)</span></span></li>
                <li><span class="a-list-item"><span class="a-text-bold">Language &rlm;: &lrm;</span><span>English</span></span></li>
                <li><span class="a-list-item"><span class="a-text-bold">Print length &rlm;: &lrm;</span><span>412 pages</span></span></li>
                <li><span class="a-list-item"><span class="a-text-bold">ISBN-10 &rlm;: &lrm;</span><span>0441013597</span></span></li>
                <li><span class="a-list-item"><span class="a-text-bold">ISBN-13 &rlm;: &lrm;</span><span>978-0441013593</span></span></li>
              </ul>
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

        $candidate = app(AmazonBookProvider::class)->lookupByCode('9780441013593')[0];

        $this->assertSame('Dune (Dune Chronicles, Book 1)', $candidate->attributes['title']);
        $this->assertSame('Frank Herbert (Author)', $candidate->attributes['authors']);
        $this->assertSame('Ace', $candidate->attributes['publisher']);
        $this->assertSame('English', $candidate->attributes['language']);
        $this->assertSame(412, $candidate->attributes['page_count']);
        $this->assertSame('0441013597', $candidate->attributes['isbn10']);
        $this->assertSame('978-0441013593', $candidate->attributes['isbn13']);
        $this->assertSame('2005-07-01', $candidate->attributes['release_date']);
        $this->assertSame('9780441013593', $candidate->attributes['ean']);
        // GitHub issue #58.
        $this->assertSame(10.49, $candidate->attributes['price']);
        $this->assertStringContainsString('adventure and mysticism', $candidate->attributes['description']);
        $this->assertSame(['https://m.media-amazon.com/images/I/dune-large.jpg'], $candidate->coverUrls);
        $this->assertSame('0441013597', $candidate->sourceId);
    }

    /** GitHub issue #58: the older, pre-corePrice_feature_div price markup is checked too. */
    public function test_price_is_read_from_the_legacy_priceblock_markup(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><body><span id="productTitle">Dune</span><div id="priceblock_ourprice">$8.99</div></body></html>',
                200
            ),
        ]);

        $candidate = app(AmazonBookProvider::class)->lookupByCode('9780441013593')[0];

        $this->assertSame(8.99, $candidate->attributes['price']);
    }

    /** GitHub issue #58: "$1,299.00" — the thousands separator must not become part of the parsed number. */
    public function test_price_strips_the_thousands_separator(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><body><span id="productTitle">Dune (Deluxe Edition)</span><div id="priceblock_ourprice">$1,299.00</div></body></html>',
                200
            ),
        ]);

        $candidate = app(AmazonBookProvider::class)->lookupByCode('9780441013593')[0];

        $this->assertSame(1299.0, $candidate->attributes['price']);
    }

    /** GitHub issue #58: a product page with no price markup at all (e.g. currently unavailable) maps to null, not a missing key or a wrong guess. */
    public function test_price_is_null_when_no_price_markup_is_present(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response('<html><body><span id="productTitle">Dune</span></body></html>', 200),
        ]);

        $candidate = app(AmazonBookProvider::class)->lookupByCode('9780441013593')[0];

        $this->assertNull($candidate->attributes['price']);
    }

    public function test_lookup_by_code_requests_the_search_page_with_the_code_as_the_query(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response($this->productPageHtml(), 200),
        ]);

        app(AmazonBookProvider::class)->lookupByCode('9780441013593');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.amazon.com/s?') && $request['k'] === '9780441013593');
    }

    public function test_a_desktop_user_agent_is_sent(): void
    {
        Http::fake([self::SEARCH_API => Http::response('<html></html>', 200)]);

        app(AmazonBookProvider::class)->lookupByCode('9780441013593');

        Http::assertSent(fn ($request) => str_contains($request->header('User-Agent')[0] ?? '', 'Mozilla'));
    }

    public function test_falls_back_to_the_search_result_when_the_product_page_fetch_fails(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response('Robot Check', 503),
        ]);

        $candidate = app(AmazonBookProvider::class)->lookupByCode('9780441013593')[0];

        $this->assertSame('Dune (Dune Chronicles, Book 1)', $candidate->attributes['title']);
        $this->assertArrayNotHasKey('isbn13', $candidate->attributes);
        $this->assertSame(['https://m.media-amazon.com/images/I/dune-thumb.jpg'], $candidate->coverUrls);
    }

    public function test_no_candidates_when_the_search_returns_no_results(): void
    {
        Http::fake([self::SEARCH_API => Http::response('<html><body>No results.</body></html>', 200)]);

        $candidates = app(AmazonBookProvider::class)->lookupByCode('0000000000000');

        $this->assertSame([], $candidates);
    }

    /** GitHub issue #53: a blocked/failed request is reported as 'failed', not silently as 'no_match' — the exact scenario this issue names AmazonScraping as an example of. */
    public function test_lookup_by_code_throws_when_the_search_request_is_blocked(): void
    {
        Http::fake([self::SEARCH_API => Http::response('Robot Check', 503)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(AmazonBookProvider::class)->lookupByCode('9780441013593');
    }

    /** search() (unlike lookupByCode()) deliberately keeps the old silent-empty behavior on a block — see AmazonScraping::amazonSearch()'s docblock. */
    public function test_search_returns_no_candidates_when_the_search_request_is_blocked(): void
    {
        Http::fake([self::SEARCH_API => Http::response('Robot Check', 503)]);

        $candidates = app(AmazonBookProvider::class)->search('Dune');

        $this->assertSame([], $candidates);
    }

    public function test_search_maps_every_result_without_fetching_individual_product_pages(): void
    {
        Http::fake([self::SEARCH_API => Http::response($this->searchResultHtml(), 200)]);

        $candidates = app(AmazonBookProvider::class)->search('dune');

        $this->assertCount(2, $candidates);
        $this->assertSame('Dune (Dune Chronicles, Book 1)', $candidates[0]->attributes['title']);
        $this->assertSame('Dune Messiah', $candidates[1]->attributes['title']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/dp/'));
    }

    public function test_configuration_requires_no_api_key(): void
    {
        $this->assertSame([], app(AmazonBookProvider::class)->configFields());
    }

    public function test_name_and_version_flag_this_as_beta(): void
    {
        $provider = app(AmazonBookProvider::class);

        $this->assertStringContainsString('Beta', $provider->name());
        $this->assertSame('v0.1-beta', $provider->version());
    }
}
