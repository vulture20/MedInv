<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Cd\AmazonCdProvider;
use App\Models\MetadataPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** AmazonCdProvider (briefing 8.2, GitHub issue #50) — see AmazonBookProviderTest's docblock for the fixture-based testing approach and why. */
class AmazonCdProviderTest extends TestCase
{
    // See AmazonBookProviderTest's matching comment (GitHub issue #210).
    use RefreshDatabase;

    private const SEARCH_API = 'https://www.amazon.com/s*';

    private const PRODUCT_API = 'https://www.amazon.com/dp/*';

    private const SEARCH_API_DE = 'https://www.amazon.de/s*';

    private function withMarketplace(string $marketplace): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'cd.amazon',
            'name' => 'Amazon',
            'media_type' => 'cd',
            'enabled' => true,
            'config' => ['marketplace' => $marketplace],
        ]);
    }

    private function searchResultHtml(): string
    {
        return <<<'HTML'
            <html><body>
            <div data-component-type="s-search-result" data-asin="B000002UJO">
              <h2><a class="a-link-normal"><span>OK Computer</span></a></h2>
              <img class="s-image" src="https://m.media-amazon.com/images/I/okc-thumb.jpg" />
            </div>
            </body></html>
            HTML;
    }

    private function productPageHtml(): string
    {
        return <<<'HTML'
            <html><body>
            <span id="productTitle"> OK Computer </span>
            <div id="bylineInfo">Radiohead (Artist)</div>
            <img id="landingImage" src="https://m.media-amazon.com/images/I/okc-small.jpg" data-old-hires="https://m.media-amazon.com/images/I/okc-large.jpg" />
            <div id="feature-bullets"><ul><li>Radiohead's seminal 1997 album.</li></ul></div>
            <div id="corePrice_feature_div"><span class="a-price"><span class="a-offscreen">$13.98</span></span></div>
            <div id="detailBullets_feature_div">
              <ul>
                <li><span class="a-list-item"><span class="a-text-bold">Format &rlm;: &lrm;</span><span>Audio CD</span></span></li>
                <li><span class="a-list-item"><span class="a-text-bold">Release Date &rlm;: &lrm;</span><span>June 17, 1997</span></span></li>
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

        $candidate = app(AmazonCdProvider::class)->lookupByCode('724385522925')[0];

        $this->assertSame('OK Computer', $candidate->attributes['title']);
        $this->assertSame('Radiohead (Artist)', $candidate->attributes['artist']);
        $this->assertSame('B000002UJO', $candidate->attributes['asin']);
        $this->assertSame('Audio CD', $candidate->attributes['medium']);
        $this->assertSame('1997-06-17', $candidate->attributes['release_date']);
        $this->assertSame('724385522925', $candidate->attributes['ean']);
        // GitHub issue #58.
        $this->assertSame(13.98, $candidate->attributes['price']);
        $this->assertSame('USD', $candidate->attributes['currency']);
        $this->assertSame(['https://m.media-amazon.com/images/I/okc-large.jpg'], $candidate->coverUrls);
        // Deliberately never a track listing — see this provider's docblock.
        $this->assertArrayNotHasKey('tracks', $candidate->attributes);
    }

    public function test_falls_back_to_the_search_result_when_the_product_page_fetch_fails(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response('Robot Check', 503),
        ]);

        $candidate = app(AmazonCdProvider::class)->lookupByCode('724385522925')[0];

        $this->assertSame('OK Computer', $candidate->attributes['title']);
        $this->assertSame('B000002UJO', $candidate->attributes['asin']);
        $this->assertArrayNotHasKey('medium', $candidate->attributes);
    }

    public function test_search_maps_every_result_without_fetching_individual_product_pages(): void
    {
        Http::fake([self::SEARCH_API => Http::response($this->searchResultHtml(), 200)]);

        $candidates = app(AmazonCdProvider::class)->search('ok computer');

        $this->assertCount(1, $candidates);
        $this->assertSame('OK Computer', $candidates[0]->attributes['title']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/dp/'));
    }

    public function test_no_candidates_when_the_search_returns_no_results(): void
    {
        Http::fake([self::SEARCH_API => Http::response('<html><body>No results.</body></html>', 200)]);

        $this->assertSame([], app(AmazonCdProvider::class)->lookupByCode('000000000000'));
    }

    /** GitHub issue #53: a blocked/failed request is reported as 'failed', not silently as 'no_match' — the exact scenario this issue names AmazonScraping as an example of. */
    public function test_lookup_by_code_throws_when_the_search_request_is_blocked(): void
    {
        Http::fake([self::SEARCH_API => Http::response('Robot Check', 503)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(AmazonCdProvider::class)->lookupByCode('000000000000');
    }

    /** No "(Beta)" suffix in the name (removed per explicit user request) — version()'s "-beta" suffix already conveys this. */
    public function test_version_flags_this_as_beta_without_a_redundant_name_suffix(): void
    {
        $provider = app(AmazonCdProvider::class);

        $this->assertSame('Amazon', $provider->name());
        $this->assertSame('v0.3-beta', $provider->version());
    }

    /** GitHub issue #210: no API key, but a marketplace select is now the one config field. */
    public function test_configuration_offers_only_the_marketplace_select(): void
    {
        $fields = app(AmazonCdProvider::class)->configFields();

        $this->assertCount(1, $fields);
        $this->assertSame('marketplace', $fields[0]->key);
        $this->assertSame(['amazon.com', 'amazon.de'], $fields[0]->options);
    }

    /** GitHub issue #210: an explicit amazon.de marketplace switches both the request host and Accept-Language — see AmazonBookProviderTest's matching test. */
    public function test_marketplace_can_be_configured_to_amazon_de(): void
    {
        $this->withMarketplace('amazon.de');
        Http::fake([self::SEARCH_API_DE => Http::response($this->searchResultHtml(), 200)]);

        app(AmazonCdProvider::class)->search('ok computer');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.amazon.de/s')
            && ($request->header('Accept-Language')[0] ?? '') === 'de-DE,de;q=0.9');
    }
}
