<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Cd\JpcCdProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * JpcCdProvider (GitHub issue #130, analogous to Amazon's #50). Unlike
 * ThaliaBookProviderTest's fixtures (built from indexed-snippet reads of a
 * site that blocked every direct fetch), jpc.de did not block a direct,
 * one-time, read-only check during development — these fixtures combine
 * what was actually confirmed on two real jpc.de pages (the `<title>` tag
 * shape, the German detail-row labels, the EAN-derived cover URL) with a
 * best-effort, never-confirmed search endpoint — see JpcScraping's own
 * docblock for exactly which parts are confirmed vs. guessed. These
 * fixtures exist to prove the *parsing logic* behaves correctly against a
 * plausible input shape, not to guarantee the real site still looks like
 * this today.
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

    /** Detail rows in "label and value share one element's text" shape (jpcDetailValue()'s first branch) — the shape actually confirmed on the real CD page checked. */
    private function productPageHtml(): string
    {
        return <<<'HTML'
            <html><head>
            <title>Mark Medlock (DSDS): Back Into The Sun (CD) – jpc.de</title>
            </head><body>
            <table>
              <tr><td>Label: Stars by Edel</td></tr>
              <tr><td>Aufnahmejahr ca.: 2026</td></tr>
              <tr><td>Artikelnummer: 12765025</td></tr>
              <tr><td>UPC/EAN: 4029759218739</td></tr>
              <tr><td>Erscheinungstermin: 14.8.2026</td></tr>
            </table>
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
        $this->assertSame('CD', $candidate->attributes['medium']);
        $this->assertSame('2026-08-14', $candidate->attributes['release_date']);
        $this->assertSame('4029759218739', $candidate->attributes['ean']);
        $this->assertSame(['https://media1.jpc.de/image/w468/front/0/4029759218739.jpg'], $candidate->coverUrls);
        $this->assertSame('12765025', $candidate->sourceId);
        // Deliberately never a track listing — see this provider's docblock.
        $this->assertArrayNotHasKey('tracks', $candidate->attributes);
        // Never extracted at all — see JpcScraping's docblock.
        $this->assertArrayNotHasKey('price', $candidate->attributes);
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

    public function test_name_key_and_version_identify_this_as_the_beta_jpc_provider(): void
    {
        $provider = app(JpcCdProvider::class);

        $this->assertSame('JPC', $provider->name());
        $this->assertSame('cd.jpc', $provider->key());
        $this->assertSame('v0.1-beta', $provider->version());
        $this->assertSame('scraping', $provider->sourceType());
    }
}
