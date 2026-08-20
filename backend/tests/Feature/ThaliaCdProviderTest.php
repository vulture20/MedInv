<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Cd\ThaliaCdProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** ThaliaCdProvider (GitHub issue #129) — see ThaliaBookProviderTest's docblock for the fixture-based testing approach and why. */
class ThaliaCdProviderTest extends TestCase
{
    private const SEARCH_API = 'https://www.thalia.de/shop/home/suche*';

    private const PRODUCT_API = 'https://www.thalia.de/shop/home/artikeldetails/*';

    private function searchResultHtml(): string
    {
        return <<<'HTML'
            <html><body>
            <a href="/shop/home/artikeldetails/musik-2/ID200200002.html">OK Computer</a>
            </body></html>
            HTML;
    }

    private function productPageHtml(): string
    {
        return <<<'HTML'
            <html><head>
            <title>OK Computer von Radiohead - Audio-CD - 724385522925 | Thalia</title>
            <script type="application/ld+json">
            {
              "@type": "MusicAlbum",
              "name": "OK Computer",
              "image": "https://bilder.thalia.media/okc-large.jpg",
              "description": "Radiohead's seminal 1997 album.",
              "byArtist": {"name": "Radiohead"},
              "datePublished": "1997-06-17",
              "offers": {"price": "13.98", "priceCurrency": "EUR"}
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

        $candidate = app(ThaliaCdProvider::class)->lookupByCode('724385522925')[0];

        $this->assertSame('OK Computer', $candidate->attributes['title']);
        $this->assertSame('Radiohead', $candidate->attributes['artist']);
        $this->assertSame('Audio-CD', $candidate->attributes['medium']);
        $this->assertSame('1997-06-17', $candidate->attributes['release_date']);
        $this->assertSame('724385522925', $candidate->attributes['ean']);
        $this->assertSame(13.98, $candidate->attributes['price']);
        $this->assertSame('EUR', $candidate->attributes['currency']);
        $this->assertSame(['https://bilder.thalia.media/okc-large.jpg'], $candidate->coverUrls);
        $this->assertSame('ID200200002', $candidate->sourceId);
        // Deliberately never a track listing — see this provider's docblock.
        $this->assertArrayNotHasKey('tracks', $candidate->attributes);
    }

    public function test_falls_back_to_the_search_result_when_the_product_page_fetch_fails(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response('', 403),
        ]);

        $candidate = app(ThaliaCdProvider::class)->lookupByCode('724385522925')[0];

        $this->assertSame('OK Computer', $candidate->attributes['title']);
        $this->assertArrayNotHasKey('medium', $candidate->attributes);
    }

    public function test_search_maps_every_result_without_fetching_individual_product_pages(): void
    {
        Http::fake([self::SEARCH_API => Http::response($this->searchResultHtml(), 200)]);

        $candidates = app(ThaliaCdProvider::class)->search('ok computer');

        $this->assertCount(1, $candidates);
        $this->assertSame('OK Computer', $candidates[0]->attributes['title']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/artikeldetails/'));
    }

    public function test_no_candidates_when_the_search_returns_no_results(): void
    {
        Http::fake([self::SEARCH_API => Http::response('<html><body>No results.</body></html>', 200)]);

        $this->assertSame([], app(ThaliaCdProvider::class)->lookupByCode('000000000000'));
    }

    /** GitHub issue #53's pattern: a blocked/failed request is reported as 'failed', not silently as 'no_match'. */
    public function test_lookup_by_code_throws_when_the_search_request_is_blocked(): void
    {
        Http::fake([self::SEARCH_API => Http::response('', 403)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(ThaliaCdProvider::class)->lookupByCode('000000000000');
    }

    public function test_name_and_version_flag_this_as_beta_without_a_redundant_name_suffix(): void
    {
        $provider = app(ThaliaCdProvider::class);

        $this->assertSame('Thalia', $provider->name());
        $this->assertSame('v0.1-beta', $provider->version());
    }
}
