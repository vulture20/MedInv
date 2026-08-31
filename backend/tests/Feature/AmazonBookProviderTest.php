<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Book\AmazonBookProvider;
use App\Models\MetadataPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AmazonBookProvider (briefing 8.2, GitHub issue #50) — Beta, see
 * AmazonScraping's docblock for why this was, for a long time, never
 * live-verified against the real amazon.com the way every other provider
 * in this codebase was, and for the single, deliberate one-time exception
 * GitHub issue #137 made to that stance. Fixtures below are hand-built
 * HTML — some (the original ones) modeled on Amazon's historically
 * documented product-page structure never actually confirmed live at the
 * time, others (added for #137) reproducing the real shapes that one-time
 * check found (the `twister-plus-buying-options-price-data` JSON blob,
 * `#bookDescription_feature_div`) — they exist to prove the *parsing
 * logic* behaves correctly against a plausible input shape, not to
 * guarantee the real site still looks like this today.
 */
class AmazonBookProviderTest extends TestCase
{
    // GitHub issue #210 — marketplace() reads metadata_plugins.config, so
    // the table needs to actually exist now, even for tests that never
    // configure a marketplace explicitly (marketplace() tolerates no row at
    // all, defaulting to 'amazon.com', but the query itself still needs a
    // real table to run against).
    use RefreshDatabase;

    private const SEARCH_API = 'https://www.amazon.com/s*';

    private const PRODUCT_API = 'https://www.amazon.com/dp/*';

    private const SEARCH_API_DE = 'https://www.amazon.de/s*';

    private const PRODUCT_API_DE = 'https://www.amazon.de/dp/*';

    private function withMarketplace(string $marketplace): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'book.amazon',
            'name' => 'Amazon',
            'media_type' => 'book',
            'enabled' => true,
            'config' => ['marketplace' => $marketplace],
        ]);
    }

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
        $this->assertSame('USD', $candidate->attributes['currency']);
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
        $this->assertNull($candidate->attributes['currency']);
    }

    /**
     * GitHub issue #137: the real buy-box price now loads client-side —
     * `#corePrice_feature_div` is genuinely empty on a real page — but a
     * hidden JSON blob seeding that same render is still present in the
     * static HTML and is read instead, taking priority over the legacy
     * markup below when both are present. Reproduces the real JSON shape
     * (including the auto-generated-looking `desktop_buybox_group_1` key)
     * confirmed on a real product page during that issue's live check.
     */
    public function test_price_and_currency_are_read_from_the_hidden_json_price_blob(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><body><span id="productTitle">Dune</span>'
                .'<div id="corePrice_feature_div"></div>'
                .'<div class="a-section aok-hidden twister-plus-buying-options-price-data">'
                .'{"desktop_buybox_group_1":[{"displayPrice":"EUR 8.85","priceAmount":8.85,"currencySymbol":"EUR"}]}'
                .'</div></body></html>',
                200
            ),
        ]);

        $candidate = app(AmazonBookProvider::class)->lookupByCode('9780441013593')[0];

        // GitHub issue #137: confirms currency is no longer hardcoded to 'USD' — a real page checked showed EUR, evidently geo-adapted by Amazon independently of the amazon.com TLD.
        $this->assertSame(8.85, $candidate->attributes['price']);
        $this->assertSame('EUR', $candidate->attributes['currency']);
    }

    /** GitHub issue #137: when the JSON price blob is absent (its markup shape wasn't confirmed for every category/page), the legacy DOM-based extraction — assumed USD, see AmazonScraping::amazonPriceAndCurrency()'s own docblock — still applies as a fallback. */
    public function test_falls_back_to_legacy_price_markup_when_the_json_blob_is_absent(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response($this->productPageHtml(), 200),
        ]);

        $candidate = app(AmazonBookProvider::class)->lookupByCode('9780441013593')[0];

        $this->assertSame(10.49, $candidate->attributes['price']);
        $this->assertSame('USD', $candidate->attributes['currency']);
    }

    /** GitHub issue #137: a real page's #bylineInfo was found to also embed a trailing "Format: {value}" segment in the same container as the actual byline, with no separating punctuation to split on. */
    public function test_authors_strips_a_trailing_format_label_bleeding_into_the_byline(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><body><span id="productTitle">Dune</span>'
                .'<div id="bylineInfo">by Frank Herbert (Author) Format: Paperback</div>'
                .'</body></html>',
                200
            ),
        ]);

        $candidate = app(AmazonBookProvider::class)->lookupByCode('9780441013593')[0];

        $this->assertSame('by Frank Herbert (Author)', $candidate->attributes['authors']);
    }

    /** GitHub issue #137: a real book page checked had no #feature-bullets/#productDescription at all — only #bookDescription_feature_div, a book-category-specific container this trait didn't know about, silently leaving description always null for books. */
    public function test_description_falls_back_to_the_book_specific_container(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><body><span id="productTitle">Dune</span>'
                .'<div id="bookDescription_feature_div">A stunning blend of adventure and mysticism.</div>'
                .'</body></html>',
                200
            ),
        ]);

        $candidate = app(AmazonBookProvider::class)->lookupByCode('9780441013593')[0];

        $this->assertStringContainsString('adventure and mysticism', $candidate->attributes['description']);
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

    /** GitHub issue #210: no API key, but a marketplace select is now the one config field. */
    public function test_configuration_offers_only_the_marketplace_select(): void
    {
        $fields = app(AmazonBookProvider::class)->configFields();

        $this->assertCount(1, $fields);
        $this->assertSame('marketplace', $fields[0]->key);
        $this->assertSame('select', $fields[0]->type);
        $this->assertSame(['amazon.com', 'amazon.de'], $fields[0]->options);
    }

    /** No "(Beta)" suffix in the name (removed per explicit user request) — version()'s "-beta" suffix already conveys this. */
    public function test_version_flags_this_as_beta_without_a_redundant_name_suffix(): void
    {
        $provider = app(AmazonBookProvider::class);

        $this->assertSame('Amazon', $provider->name());
        $this->assertSame('v0.3-beta', $provider->version());
    }

    /** GitHub issue #210: with no metadata_plugins row at all (unconfigured), the default marketplace stays amazon.com — unchanged behavior. */
    public function test_marketplace_defaults_to_amazon_com_when_unconfigured(): void
    {
        Http::fake([self::SEARCH_API => Http::response($this->searchResultHtml(), 200)]);

        app(AmazonBookProvider::class)->search('dune');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.amazon.com/s')
            && ($request->header('Accept-Language')[0] ?? '') === 'en-US,en;q=0.9');
    }

    /** GitHub issue #210: an explicit amazon.de marketplace switches both the request host and Accept-Language. */
    public function test_marketplace_can_be_configured_to_amazon_de(): void
    {
        $this->withMarketplace('amazon.de');
        Http::fake([self::SEARCH_API_DE => Http::response($this->searchResultHtml(), 200)]);

        app(AmazonBookProvider::class)->search('dune');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.amazon.de/s')
            && ($request->header('Accept-Language')[0] ?? '') === 'de-DE,de;q=0.9');
    }

    /**
     * GitHub issue #211: Amazon's buy-box price now renders as plain
     * a-price-whole/-decimal/-fraction/-symbol spans inside
     * #corePriceDisplay_desktop_feature_div (confirmed live against a real
     * amazon.de page during #210's own research) — neither the JSON blob
     * nor the legacy #corePrice_feature_div id were present there any more.
     * Currency is derived from the € symbol via CURRENCY_SYMBOL_TO_ISO.
     */
    public function test_price_is_read_from_the_current_price_to_pay_markup(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><body><span id="productTitle">Dune</span>'
                .'<div id="corePriceDisplay_desktop_feature_div">'
                .'<span class="a-price aok-align-center reinventPricePriceToPayMargin priceToPay apex-pricetopay-value">'
                .'<span class="a-offscreen"> </span><span aria-hidden="true">'
                .'<span class="a-price-whole">38<span class="a-price-decimal">,</span></span>'
                .'<span class="a-price-fraction">52</span><span class="a-price-symbol">€</span>'
                .'</span></span></div></body></html>',
                200
            ),
        ]);

        $candidate = app(AmazonBookProvider::class)->lookupByCode('9780441013593')[0];

        $this->assertSame(38.52, $candidate->attributes['price']);
        $this->assertSame('EUR', $candidate->attributes['currency']);
    }
}
