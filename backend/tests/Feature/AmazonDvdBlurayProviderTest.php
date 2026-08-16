<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\DvdBluray\AmazonDvdBlurayProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** AmazonDvdBlurayProvider (briefing 8.2, GitHub issue #50) — see AmazonBookProviderTest's docblock for the fixture-based testing approach and why. */
class AmazonDvdBlurayProviderTest extends TestCase
{
    private const SEARCH_API = 'https://www.amazon.com/s*';

    private const PRODUCT_API = 'https://www.amazon.com/dp/*';

    private function searchResultHtml(): string
    {
        return <<<'HTML'
            <html><body>
            <div data-component-type="s-search-result" data-asin="B000AYW0BQ">
              <h2><a class="a-link-normal"><span>Blade Runner</span></a></h2>
              <img class="s-image" src="https://m.media-amazon.com/images/I/br-thumb.jpg" />
            </div>
            </body></html>
            HTML;
    }

    private function productPageHtml(): string
    {
        return <<<'HTML'
            <html><body>
            <span id="productTitle"> Blade Runner </span>
            <div id="bylineInfo">Starring: Harrison Ford, Rutger Hauer</div>
            <img id="landingImage" src="https://m.media-amazon.com/images/I/br-small.jpg" data-old-hires="https://m.media-amazon.com/images/I/br-large.jpg" />
            <div id="feature-bullets"><ul><li>A neo-noir science fiction classic.</li></ul></div>
            <div id="corePrice_feature_div"><span class="a-price"><span class="a-offscreen">$19.99</span></span></div>
            <div id="detailBullets_feature_div">
              <ul>
                <li><span class="a-list-item"><span class="a-text-bold">Format &rlm;: &lrm;</span><span>Blu-ray</span></span></li>
                <li><span class="a-list-item"><span class="a-text-bold">Run time &rlm;: &lrm;</span><span>117 minutes</span></span></li>
                <li><span class="a-list-item"><span class="a-text-bold">Director &rlm;: &lrm;</span><span>Ridley Scott</span></span></li>
                <li><span class="a-list-item"><span class="a-text-bold">Language &rlm;: &lrm;</span><span>English</span></span></li>
                <li><span class="a-list-item"><span class="a-text-bold">Release Date &rlm;: &lrm;</span><span>October 4, 2007</span></span></li>
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

        $candidate = app(AmazonDvdBlurayProvider::class)->lookupByCode('012569783680')[0];

        $this->assertSame('Blade Runner', $candidate->attributes['title']);
        $this->assertSame('Blu-ray', $candidate->attributes['medium']);
        $this->assertSame(117, $candidate->attributes['runtime_minutes']);
        $this->assertSame('Ridley Scott', $candidate->attributes['director']);
        $this->assertSame('English', $candidate->attributes['languages']);
        $this->assertSame('2007-10-04', $candidate->attributes['release_date']);
        $this->assertSame(2007, $candidate->attributes['production_year']);
        $this->assertSame('012569783680', $candidate->attributes['ean']);
        // GitHub issue #58.
        $this->assertSame(19.99, $candidate->attributes['price']);
        $this->assertSame('USD', $candidate->attributes['currency']);
        $this->assertSame(['https://m.media-amazon.com/images/I/br-large.jpg'], $candidate->coverUrls);
        // No dedicated "Actors" bullet in this fixture — falls back to bylineInfo.
        $this->assertSame('Starring: Harrison Ford, Rutger Hauer', $candidate->attributes['cast']);
    }

    public function test_falls_back_to_the_search_result_when_the_product_page_fetch_fails(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response('Robot Check', 503),
        ]);

        $candidate = app(AmazonDvdBlurayProvider::class)->lookupByCode('012569783680')[0];

        $this->assertSame('Blade Runner', $candidate->attributes['title']);
        $this->assertArrayNotHasKey('runtime_minutes', $candidate->attributes);
    }

    public function test_search_maps_every_result_without_fetching_individual_product_pages(): void
    {
        Http::fake([self::SEARCH_API => Http::response($this->searchResultHtml(), 200)]);

        $candidates = app(AmazonDvdBlurayProvider::class)->search('blade runner');

        $this->assertCount(1, $candidates);
        $this->assertSame('Blade Runner', $candidates[0]->attributes['title']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/dp/'));
    }

    /** GitHub issue #53: a blocked/failed request is reported as 'failed', not silently as 'no_match' — the exact scenario this issue names AmazonScraping as an example of. */
    public function test_lookup_by_code_throws_when_the_search_request_is_blocked(): void
    {
        Http::fake([self::SEARCH_API => Http::response('Robot Check', 503)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(AmazonDvdBlurayProvider::class)->lookupByCode('000000000000');
    }

    /** No "(Beta)" suffix in the name (removed per explicit user request) — version()'s "-beta" suffix already conveys this. */
    public function test_version_flags_this_as_beta_without_a_redundant_name_suffix(): void
    {
        $provider = app(AmazonDvdBlurayProvider::class);

        $this->assertSame('Amazon', $provider->name());
        $this->assertSame('v0.1-beta', $provider->version());
    }
}
