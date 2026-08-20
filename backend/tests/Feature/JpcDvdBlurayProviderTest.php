<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\DvdBluray\JpcDvdBlurayProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** JpcDvdBlurayProvider (GitHub issue #130) — see JpcCdProviderTest's docblock for the fixture-based testing approach and why. */
class JpcDvdBlurayProviderTest extends TestCase
{
    private const SEARCH_API = 'https://www.jpc.de/jpcng/search*';

    private const PRODUCT_API = 'https://www.jpc.de/jpcng/*/detail/-/art/*';

    private function searchResultHtml(): string
    {
        return <<<'HTML'
            <html><body>
            <a href="/jpcng/movie/detail/-/art/das-wandelnde-schloss-bd-dvd/hnum/6171414">Das wandelnde Schloss</a>
            </body></html>
            HTML;
    }

    /** Detail rows in "label is its own element, value is the next sibling" shape (jpcDetailValue()'s second branch) — the other confirmed real shape, exercised here rather than duplicating JpcCdProviderTest's first-branch fixture. */
    private function productPageHtml(): string
    {
        return <<<'HTML'
            <html><head>
            <title>Das wandelnde Schloss (Blu-ray & DVD im Steelbook) – jpc.de</title>
            </head><body>
            <dl>
              <dt>Herkunftsland:</dt><dd>Japan, 2004</dd>
              <dt>UPC/EAN:</dt><dd>0889853970292</dd>
              <dt>Erscheinungstermin:</dt><dd>5.5.2017</dd>
              <dt>Spieldauer ca.:</dt><dd>119 Min.</dd>
              <dt>Regie:</dt><dd>Hayao Miyazaki</dd>
              <dt>Sprache:</dt><dd>Deutsch, Japanisch</dd>
            </dl>
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
        $this->assertSame(119, $candidate->attributes['runtime_minutes']);
        $this->assertSame('Hayao Miyazaki', $candidate->attributes['director']);
        $this->assertSame('Deutsch, Japanisch', $candidate->attributes['languages']);
        $this->assertSame('2017-05-05', $candidate->attributes['release_date']);
        $this->assertSame(2017, $candidate->attributes['production_year']);
        $this->assertSame('0889853970292', $candidate->attributes['ean']);
        $this->assertSame(['https://media1.jpc.de/image/w468/front/0/0889853970292.jpg'], $candidate->coverUrls);
        $this->assertSame('6171414', $candidate->sourceId);
        // No confirmed "Darsteller" label — see this provider's docblock.
        $this->assertArrayNotHasKey('cast', $candidate->attributes);
        $this->assertArrayNotHasKey('price', $candidate->attributes);
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

    public function test_name_and_version_flag_this_as_beta_without_a_redundant_name_suffix(): void
    {
        $provider = app(JpcDvdBlurayProvider::class);

        $this->assertSame('JPC', $provider->name());
        $this->assertSame('v0.1-beta', $provider->version());
    }
}
