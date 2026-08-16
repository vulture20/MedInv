<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Cd\AmazonCdProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** AmazonCdProvider (briefing 8.2, GitHub issue #50) — see AmazonBookProviderTest's docblock for the fixture-based testing approach and why. */
class AmazonCdProviderTest extends TestCase
{
    private const SEARCH_API = 'https://www.amazon.com/s*';

    private const PRODUCT_API = 'https://www.amazon.com/dp/*';

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

    public function test_name_and_version_flag_this_as_beta(): void
    {
        $provider = app(AmazonCdProvider::class);

        $this->assertStringContainsString('Beta', $provider->name());
        $this->assertSame('v0.1-beta', $provider->version());
    }
}
