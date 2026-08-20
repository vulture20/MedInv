<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\DvdBluray\ThaliaDvdBlurayProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** ThaliaDvdBlurayProvider (GitHub issue #129) — see ThaliaBookProviderTest's docblock for the fixture-based testing approach and why. */
class ThaliaDvdBlurayProviderTest extends TestCase
{
    private const SEARCH_API = 'https://www.thalia.de/shop/home/suche*';

    private const PRODUCT_API = 'https://www.thalia.de/shop/home/artikeldetails/*';

    private function searchResultHtml(): string
    {
        return <<<'HTML'
            <html><body>
            <a href="/shop/home/artikeldetails/filme-3/ID1072285836.html">Blade Runner</a>
            </body></html>
            HTML;
    }

    private function productPageHtml(): string
    {
        return <<<'HTML'
            <html><head>
            <title>Blade Runner von Ridley Scott - Blu-ray - 0012569783680 | Thalia</title>
            <script type="application/ld+json">
            {
              "@type": "Movie",
              "name": "Blade Runner",
              "image": "https://bilder.thalia.media/br-large.jpg",
              "description": "A neo-noir science fiction classic.",
              "director": {"name": "Ridley Scott"},
              "inLanguage": "de",
              "datePublished": "2007-10-04",
              "duration": "PT117M",
              "offers": {"price": "19.99", "priceCurrency": "EUR"}
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

        $candidate = app(ThaliaDvdBlurayProvider::class)->lookupByCode('0012569783680')[0];

        $this->assertSame('Blade Runner', $candidate->attributes['title']);
        $this->assertSame('Blu-ray', $candidate->attributes['medium']);
        $this->assertSame(117, $candidate->attributes['runtime_minutes']);
        $this->assertSame('Ridley Scott', $candidate->attributes['director']);
        $this->assertSame('de', $candidate->attributes['languages']);
        $this->assertSame('2007-10-04', $candidate->attributes['release_date']);
        $this->assertSame(2007, $candidate->attributes['production_year']);
        $this->assertSame('0012569783680', $candidate->attributes['ean']);
        $this->assertSame(19.99, $candidate->attributes['price']);
        $this->assertSame('EUR', $candidate->attributes['currency']);
        $this->assertSame(['https://bilder.thalia.media/br-large.jpg'], $candidate->coverUrls);
        $this->assertSame('ID1072285836', $candidate->sourceId);
        // No distinct "cast" signal exists in anything this trait extracts — see this provider's docblock.
        $this->assertArrayNotHasKey('cast', $candidate->attributes);
    }

    public function test_falls_back_to_the_search_result_when_the_product_page_fetch_fails(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response('', 403),
        ]);

        $candidate = app(ThaliaDvdBlurayProvider::class)->lookupByCode('0012569783680')[0];

        $this->assertSame('Blade Runner', $candidate->attributes['title']);
        $this->assertArrayNotHasKey('runtime_minutes', $candidate->attributes);
    }

    public function test_search_maps_every_result_without_fetching_individual_product_pages(): void
    {
        Http::fake([self::SEARCH_API => Http::response($this->searchResultHtml(), 200)]);

        $candidates = app(ThaliaDvdBlurayProvider::class)->search('blade runner');

        $this->assertCount(1, $candidates);
        $this->assertSame('Blade Runner', $candidates[0]->attributes['title']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/artikeldetails/'));
    }

    /** GitHub issue #53's pattern: a blocked/failed request is reported as 'failed', not silently as 'no_match'. */
    public function test_lookup_by_code_throws_when_the_search_request_is_blocked(): void
    {
        Http::fake([self::SEARCH_API => Http::response('', 403)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(ThaliaDvdBlurayProvider::class)->lookupByCode('000000000000');
    }

    public function test_name_and_version_flag_this_as_beta_without_a_redundant_name_suffix(): void
    {
        $provider = app(ThaliaDvdBlurayProvider::class);

        $this->assertSame('Thalia', $provider->name());
        $this->assertSame('v0.1-beta', $provider->version());
    }
}
