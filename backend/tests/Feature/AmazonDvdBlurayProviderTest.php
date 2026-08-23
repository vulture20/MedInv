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
                <li><span class="a-list-item"><span class="a-text-bold">Actors &rlm;: &lrm;</span><span>Harrison Ford, Rutger Hauer, Sean Young</span></span></li>
                <li><span class="a-list-item"><span class="a-text-bold">Language &rlm;: &lrm;</span><span>English</span></span></li>
                <li><span class="a-list-item"><span class="a-text-bold">Genre &rlm;: &lrm;</span><span>Science Fiction</span></span></li>
                <li><span class="a-list-item"><span class="a-text-bold">Subtitles &rlm;: &lrm;</span><span>English</span></span></li>
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
        // GitHub issue #140/#141: 'genre' was originally an unconfirmed
        // guess, then confirmed via a real product page — see this
        // provider's own docblock.
        $this->assertSame('Science Fiction', $candidate->attributes['genre']);
        $this->assertSame('English', $candidate->attributes['subtitles']);
        $this->assertSame('2007-10-04', $candidate->attributes['release_date']);
        $this->assertSame(2007, $candidate->attributes['production_year']);
        $this->assertSame('012569783680', $candidate->attributes['ean']);
        // GitHub issue #58.
        $this->assertSame(19.99, $candidate->attributes['price']);
        $this->assertSame('USD', $candidate->attributes['currency']);
        $this->assertSame(['https://m.media-amazon.com/images/I/br-large.jpg'], $candidate->coverUrls);
        // GitHub issue #173: sourced from the 'Actors' bullet, not
        // #bylineInfo (still "Starring: Harrison Ford, Rutger Hauer" in
        // this fixture, deliberately different text) — proves the bullet
        // is what's actually used, not a byline fallback (removed for
        // good reason, see this provider's own docblock).
        $this->assertSame('Harrison Ford, Rutger Hauer, Sean Young', $candidate->attributes['cast']);
    }

    /**
     * GitHub issue #141: a real product page provided by the user
     * ("Ant-Man" Blu-ray, B07447J2TS) showed "Genre" living in a third
     * detail-bullet shape, `#productOverview_feature_div` — a compact
     * table with `<tr class="… po-{field}">` rows — entirely separate
     * from `detailBullets_feature_div`, which didn't carry a Genre bullet
     * at all on that page. Mirrors that real shape rather than the
     * original `detailBullets_feature_div` fixture used above, so this
     * specifically exercises the merge added to
     * AmazonScraping::amazonDetailBullets() rather than re-testing what
     * the first test already covers.
     */
    public function test_genre_is_extracted_from_the_product_overview_table(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><body><span id="productTitle">Ant-Man</span>'
                .'<div id="productOverview_feature_div"><table><tbody>'
                .'<tr class="a-spacing-small po-genre"><td><span class="a-text-bold">Genre</span></td><td><span class="po-break-word">Action/Adventure</span></td></tr>'
                .'<tr class="a-spacing-small po-format"><td><span class="a-text-bold">Format</span></td><td><span class="po-break-word">Blu-ray</span></td></tr>'
                .'</tbody></table></div></body></html>',
                200
            ),
        ]);

        $candidate = app(AmazonDvdBlurayProvider::class)->lookupByCode('012569783680')[0];

        $this->assertSame('Action/Adventure', $candidate->attributes['genre']);
        $this->assertSame('Blu-ray', $candidate->attributes['medium']);
    }

    /**
     * GitHub issue #141: the same real page that confirmed 'Genre' was
     * served in German — "Subtitles" itself was never confirmed in
     * English, but the real "Untertitel"/"Medienformat" labels found
     * there were added as purely additive fallbacks (never matching an
     * English page, so this can't regress the primary English guess).
     */
    public function test_subtitles_and_medium_fall_back_to_their_confirmed_german_labels(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><body><span id="productTitle">Ant-Man</span>'
                .'<div id="detailBullets_feature_div"><ul>'
                .'<li><span class="a-list-item"><span class="a-text-bold">Medienformat &rlm;: &lrm;</span><span>Blu-ray</span></span></li>'
                .'<li><span class="a-list-item"><span class="a-text-bold">Untertitel: &rlm;: &lrm;</span><span>Französisch, Spanisch</span></span></li>'
                .'</ul></div></body></html>',
                200
            ),
        ]);

        $candidate = app(AmazonDvdBlurayProvider::class)->lookupByCode('012569783680')[0];

        $this->assertSame('Blu-ray', $candidate->attributes['medium']);
        $this->assertSame('Französisch, Spanisch', $candidate->attributes['subtitles']);
    }

    /**
     * GitHub issue #139: a user reported "Format: DVD" landing in `cast`
     * for a real item — not independently confirmed live at the time
     * (both re-check attempts were blocked by Amazon), so
     * stripAmazonFormatContamination() was applied here as defensive,
     * unverified-shape hardening. Still applies now that `cast` has been
     * reintroduced (GitHub issue #173) — the contamination sits in the
     * *middle* of the "Actors" bullet value here, proving the widened
     * regex handles that shape too, not just a trailing one.
     */
    public function test_cast_strips_format_contamination_from_the_actors_bullet(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><body><span id="productTitle">Hogfather</span>'
                .'<div id="detailBullets_feature_div"><ul>'
                .'<li><span class="a-list-item"><span class="a-text-bold">Actors &rlm;: &lrm;</span><span>David Warner, Ian Richardson Format: DVD Michelle Dockery</span></span></li>'
                .'</ul></div></body></html>',
                200
            ),
        ]);

        $candidate = app(AmazonDvdBlurayProvider::class)->lookupByCode('4009750242353')[0];

        $this->assertSame('David Warner, Ian Richardson Michelle Dockery', $candidate->attributes['cast']);
    }

    /**
     * GitHub issue #141's real dump confirmed a German "Darsteller" bullet
     * holding exactly this same field's data, on the same real page that
     * later (GitHub issue #173) confirmed the English "Actors" label too
     * — added as a purely additive fallback, same as `subtitles`/`medium`
     * already have their own confirmed German fallbacks.
     */
    public function test_cast_falls_back_to_the_confirmed_german_darsteller_label(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><body><span id="productTitle">Ant-Man</span>'
                .'<div id="detailBullets_feature_div"><ul>'
                .'<li><span class="a-list-item"><span class="a-text-bold">Darsteller &rlm;: &lrm;</span><span>Evangeline Lilly, Michael Douglas, Paul Rudd</span></span></li>'
                .'</ul></div></body></html>',
                200
            ),
        ]);

        $candidate = app(AmazonDvdBlurayProvider::class)->lookupByCode('012569783680')[0];

        $this->assertSame('Evangeline Lilly, Michael Douglas, Paul Rudd', $candidate->attributes['cast']);
    }

    /**
     * GitHub issue #173: unlike the pre-#150 version of this field, a page
     * with no `Actors`/`Darsteller` bullet no longer falls back to
     * `#bylineInfo` — the real page that confirmed `Actors` also showed
     * `bylineInfo` mixing actors *and* crew in one run-on string (e.g.
     * "Paul Rudd (Actor, Writer), ... Peyton Reed (Director) ..."), very
     * plausibly the real cause behind #150's "cast is wrong in general"
     * report. `cast` now just stays null in that case, same as
     * `genre`/`director`/`subtitles` already do when their own bullet is
     * absent, rather than risking that mixed-roles data again.
     */
    public function test_cast_is_not_set_from_byline_when_no_actors_bullet_is_present(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResultHtml(), 200),
            self::PRODUCT_API => Http::response(
                '<html><body><span id="productTitle">Ant-Man</span>'
                .'<div id="bylineInfo">Paul Rudd (Actor, Writer), Peyton Reed (Director)</div>'
                .'</body></html>',
                200
            ),
        ]);

        $candidate = app(AmazonDvdBlurayProvider::class)->lookupByCode('012569783680')[0];

        $this->assertNull($candidate->attributes['cast']);
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
