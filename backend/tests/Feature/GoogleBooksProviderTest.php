<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Book\GoogleBooksProvider;
use App\Models\MetadataPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GoogleBooksProvider (GitHub issue #20, plus a follow-up bug report
 * against the same issue). The Murdoku fixtures below are trimmed copies
 * of real, live-fetched responses for EAN/ISBN-13 9783742310026 — the
 * search-by-ISBN endpoint (`?q=isbn:...`) omits `publisher` entirely (and
 * always reports `pageCount: 0` rather than omitting the key) for this
 * record, even though a dedicated by-ID GET (.../volumes/{id}) has
 * `publisher`. Neither response has `description`/`categories`/
 * `imageLinks` at all — confirmed live to be genuinely absent from
 * Google's own catalog for this particular (sparsely-catalogued) title,
 * not something either endpoint is hiding.
 */
class GoogleBooksProviderTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH_API = 'https://www.googleapis.com/books/v1/volumes?*';

    private const BY_ID_API = 'https://www.googleapis.com/books/v1/volumes/*';

    /** Search-endpoint result for "Project Hail Mary" — this one already has full volumeInfo, unlike Murdoku below. */
    private function hailMarySearchResponse(): array
    {
        return [
            'kind' => 'books#volumes',
            'totalItems' => 1,
            'items' => [$this->hailMaryVolume()],
        ];
    }

    private function hailMaryVolume(): array
    {
        return [
            'id' => 'zyTCAlFPjgYC',
            'volumeInfo' => [
                'title' => 'Project Hail Mary',
                'authors' => ['Andy Weir'],
                'publisher' => 'Ballantine Books',
                'publishedDate' => '2021-05-04',
                'description' => 'A lone astronaut must save the earth from disaster.',
                'industryIdentifiers' => [
                    ['type' => 'ISBN_10', 'identifier' => '0593135202'],
                    ['type' => 'ISBN_13', 'identifier' => '9780593135204'],
                ],
                'pageCount' => 496,
                'categories' => ['Fiction'],
                'language' => 'en',
                'imageLinks' => [
                    'smallThumbnail' => 'http://books.google.com/books/content?id=zyTCAlFPjgYC&img=1&zoom=5',
                    'thumbnail' => 'http://books.google.com/books/content?id=zyTCAlFPjgYC&img=1&zoom=1',
                ],
            ],
            // GitHub issue #58 — a sibling of volumeInfo, not nested inside it.
            'saleInfo' => [
                'saleability' => 'FOR_SALE',
                'listPrice' => ['amount' => 18.0, 'currencyCode' => 'USD'],
                'retailPrice' => ['amount' => 14.99, 'currencyCode' => 'USD'],
            ],
        ];
    }

    /** Real (trimmed) `?q=isbn:9783742310026` response — no `publisher`, `pageCount` is a literal 0. */
    private function murdokuSearchResponse(): array
    {
        return [
            'kind' => 'books#volumes',
            'totalItems' => 1,
            'items' => [
                [
                    'id' => 'w3jE0QEACAAJ',
                    'volumeInfo' => [
                        'title' => 'Murdoku',
                        'authors' => ['Manuel Garand'],
                        'publishedDate' => '2026',
                        'industryIdentifiers' => [
                            ['type' => 'ISBN_10', 'identifier' => '374231002X'],
                            ['type' => 'ISBN_13', 'identifier' => '9783742310026'],
                        ],
                        'pageCount' => 0,
                        'language' => 'de',
                    ],
                ],
            ],
        ];
    }

    /** Real (trimmed) `.../v1/volumes/w3jE0QEACAAJ` response for the same book — has `publisher`, but still no `pageCount`/`description`/`categories`/`imageLinks`. */
    private function murdokuVolumeResponse(): array
    {
        return [
            'id' => 'w3jE0QEACAAJ',
            'volumeInfo' => [
                'title' => 'Murdoku',
                'authors' => ['Manuel Garand'],
                'publisher' => 'riva',
                'publishedDate' => '2026',
                'industryIdentifiers' => [
                    ['type' => 'ISBN_10', 'identifier' => '374231002X'],
                    ['type' => 'ISBN_13', 'identifier' => '9783742310026'],
                ],
                'language' => 'de',
            ],
        ];
    }

    public function test_lookup_by_code_maps_the_first_item_to_a_candidate(): void
    {
        Http::fake([
            self::BY_ID_API => Http::response($this->hailMaryVolume(), 200),
            self::SEARCH_API => Http::response($this->hailMarySearchResponse(), 200),
        ]);

        $candidate = app(GoogleBooksProvider::class)->lookupByCode('9780593135204')[0];

        $this->assertSame('Project Hail Mary', $candidate->attributes['title']);
        $this->assertSame('Andy Weir', $candidate->attributes['authors']);
        $this->assertSame('Ballantine Books', $candidate->attributes['publisher']);
        $this->assertSame('2021-05-04', $candidate->attributes['release_date']);
        $this->assertSame('0593135202', $candidate->attributes['isbn10']);
        $this->assertSame('9780593135204', $candidate->attributes['isbn13']);
        $this->assertSame('9780593135204', $candidate->attributes['ean']);
        $this->assertSame(496, $candidate->attributes['page_count']);
        $this->assertSame('Fiction', $candidate->attributes['genre']);
        $this->assertSame('en', $candidate->attributes['language']);
        // GitHub issue #58: listPrice is preferred over retailPrice, see salePrice()'s docblock.
        $this->assertSame(18.0, $candidate->attributes['price']);
        $this->assertSame('USD', $candidate->attributes['currency']);
    }

    /** GitHub issue #58: a NOT_FOR_SALE book (out of print, ...) can still carry a stale/zero price object — not a real price to store. */
    public function test_price_is_null_when_the_book_is_not_for_sale(): void
    {
        $volume = $this->hailMaryVolume();
        $volume['saleInfo']['saleability'] = 'NOT_FOR_SALE';
        $searchResponse = $this->hailMarySearchResponse();
        $searchResponse['items'] = [$volume];

        Http::fake([
            self::BY_ID_API => Http::response($volume, 200),
            self::SEARCH_API => Http::response($searchResponse, 200),
        ]);

        $candidate = app(GoogleBooksProvider::class)->lookupByCode('9780593135204')[0];

        $this->assertNull($candidate->attributes['price']);
        $this->assertNull($candidate->attributes['currency']);
    }

    /** GitHub issue #58: falls back to retailPrice when listPrice is absent. */
    public function test_price_falls_back_to_retail_price_when_list_price_is_absent(): void
    {
        $volume = $this->hailMaryVolume();
        unset($volume['saleInfo']['listPrice']);
        $volume['saleInfo']['retailPrice'] = ['amount' => 12.5, 'currencyCode' => 'EUR'];
        $searchResponse = $this->hailMarySearchResponse();
        $searchResponse['items'] = [$volume];

        Http::fake([
            self::BY_ID_API => Http::response($volume, 200),
            self::SEARCH_API => Http::response($searchResponse, 200),
        ]);

        $candidate = app(GoogleBooksProvider::class)->lookupByCode('9780593135204')[0];

        $this->assertSame(12.5, $candidate->attributes['price']);
        $this->assertSame('EUR', $candidate->attributes['currency']);
    }

    public function test_cover_urls_are_upgraded_from_http_to_https(): void
    {
        Http::fake([
            self::BY_ID_API => Http::response($this->hailMaryVolume(), 200),
            self::SEARCH_API => Http::response($this->hailMarySearchResponse(), 200),
        ]);

        $candidate = app(GoogleBooksProvider::class)->lookupByCode('9780593135204')[0];

        $this->assertTrue(collect($candidate->coverUrls)->every(fn (string $url) => str_starts_with($url, 'https://')));
        $this->assertNotEmpty($candidate->coverUrls);
    }

    /**
     * Regression test for "the downloaded cover is much too small": both
     * imageLinks entries encode a small default `zoom` (1 and 5, per the
     * real fixture below) — cover_urls[0] must request a larger rendition
     * instead of whatever Google linked by default.
     */
    public function test_cover_urls_request_a_larger_zoom_level_than_googles_default(): void
    {
        Http::fake([
            self::BY_ID_API => Http::response($this->hailMaryVolume(), 200),
            self::SEARCH_API => Http::response($this->hailMarySearchResponse(), 200),
        ]);

        $candidate = app(GoogleBooksProvider::class)->lookupByCode('9780593135204')[0];

        $this->assertNotEmpty($candidate->coverUrls);
        foreach ($candidate->coverUrls as $url) {
            $this->assertStringContainsString('zoom=3', $url);
            $this->assertStringNotContainsString('zoom=1', $url);
            $this->assertStringNotContainsString('zoom=5', $url);
        }
    }

    /** Regression test for the bug reported against EAN 9783742310026 — see class docblock. */
    public function test_publisher_missing_from_the_search_result_is_filled_in_from_the_full_volume_record(): void
    {
        Http::fake([
            self::BY_ID_API => Http::response($this->murdokuVolumeResponse(), 200),
            self::SEARCH_API => Http::response($this->murdokuSearchResponse(), 200),
        ]);

        $candidate = app(GoogleBooksProvider::class)->lookupByCode('9783742310026')[0];

        $this->assertSame('riva', $candidate->attributes['publisher']);
        $this->assertSame('Murdoku', $candidate->attributes['title']);
    }

    public function test_a_literal_zero_page_count_is_treated_as_unknown_rather_than_a_real_value(): void
    {
        Http::fake([
            self::BY_ID_API => Http::response($this->murdokuVolumeResponse(), 200),
            self::SEARCH_API => Http::response($this->murdokuSearchResponse(), 200),
        ]);

        $candidate = app(GoogleBooksProvider::class)->lookupByCode('9783742310026')[0];

        $this->assertNull($candidate->attributes['page_count']);
    }

    public function test_falls_back_to_the_search_result_when_the_full_volume_fetch_fails(): void
    {
        Http::fake([
            self::BY_ID_API => Http::response([], 404),
            self::SEARCH_API => Http::response($this->murdokuSearchResponse(), 200),
        ]);

        $candidate = app(GoogleBooksProvider::class)->lookupByCode('9783742310026')[0];

        // No publisher available at all now (it only ever came from the
        // failed by-ID call), but the rest of the search-result data is
        // still used rather than the whole lookup failing.
        $this->assertNull($candidate->attributes['publisher']);
        $this->assertSame('Murdoku', $candidate->attributes['title']);
    }

    public function test_no_candidates_when_the_api_has_no_matching_item(): void
    {
        Http::fake([self::SEARCH_API => Http::response(['kind' => 'books#volumes', 'totalItems' => 0], 200)]);

        $candidates = app(GoogleBooksProvider::class)->lookupByCode('0000000000000');

        $this->assertSame([], $candidates);
    }

    /** GitHub issue #53: a failed request (e.g. quota exceeded) is reported as 'failed', not silently as 'no_match'. */
    public function test_lookup_by_code_throws_when_the_search_request_fails_eg_quota_exceeded(): void
    {
        Http::fake([self::SEARCH_API => Http::response(['error' => ['code' => 429]], 429)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(GoogleBooksProvider::class)->lookupByCode('9780593135204');
    }

    public function test_search_maps_every_item_without_the_extra_by_id_enrichment_call(): void
    {
        $response = $this->hailMarySearchResponse();
        $response['items'][] = $this->hailMaryVolume();
        $response['items'][1]['id'] = 'anotherId';
        Http::fake([self::SEARCH_API => Http::response($response, 200)]);

        $candidates = app(GoogleBooksProvider::class)->search('project hail mary');

        $this->assertCount(2, $candidates);
        // search() has no known EAN for the query itself, unlike lookupByCode().
        $this->assertNull($candidates[0]->attributes['ean']);
        // Deliberately no by-ID enrichment for search results — see search()'s docblock.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/volumes/zyTCAlFPjgYC'));
    }

    public function test_configured_api_key_is_sent_as_a_query_parameter_on_both_requests(): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'book.google_books',
            'name' => 'Google Books',
            'media_type' => 'book',
            'enabled' => true,
            'config' => ['api_key' => 'secret-key-123'],
        ]);
        Http::fake([
            self::BY_ID_API => Http::response($this->hailMaryVolume(), 200),
            self::SEARCH_API => Http::response($this->hailMarySearchResponse(), 200),
        ]);

        app(GoogleBooksProvider::class)->lookupByCode('9780593135204');

        Http::assertSent(fn ($request) => $request['key'] === 'secret-key-123' && str_contains($request->url(), '?q='));
        Http::assertSent(fn ($request) => $request['key'] === 'secret-key-123' && str_contains($request->url(), '/volumes/zyTCAlFPjgYC'));
    }

    public function test_works_without_any_configured_api_key(): void
    {
        Http::fake([
            self::BY_ID_API => Http::response($this->hailMaryVolume(), 200),
            self::SEARCH_API => Http::response($this->hailMarySearchResponse(), 200),
        ]);

        $candidates = app(GoogleBooksProvider::class)->lookupByCode('9780593135204');

        $this->assertNotEmpty($candidates);
        Http::assertSent(fn ($request) => ! isset($request['key']));
    }

    /** GitHub issue #164: the whole point — live-confirmed against the real API (a bogus key), not the issue's own guessed reason/status. */
    public function test_test_config_returns_false_for_an_invalid_key(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response([
                'error' => ['code' => 400, 'message' => 'API key not valid. Please pass a valid API key.', 'errors' => [['reason' => 'badRequest']]],
            ], 400),
        ]);

        $valid = app(GoogleBooksProvider::class)->testConfig(['api_key' => 'a-bogus-key']);

        $this->assertFalse($valid);
    }

    public function test_test_config_also_treats_403_as_invalid(): void
    {
        Http::fake([self::SEARCH_API => Http::response(['error' => ['code' => 403]], 403)]);

        $this->assertFalse(app(GoogleBooksProvider::class)->testConfig(['api_key' => 'an-unauthorized-key']));
    }

    public function test_test_config_returns_true_for_a_valid_key(): void
    {
        Http::fake([self::SEARCH_API => Http::response(['totalItems' => 0, 'items' => []], 200)]);

        $valid = app(GoogleBooksProvider::class)->testConfig(['api_key' => 'a-valid-key']);

        $this->assertTrue($valid);
        Http::assertSent(fn ($request) => $request['key'] === 'a-valid-key' && $request['q'] === 'isbn:0000000000');
    }

    /** The field is optional (this class's own docblock) — nothing to test without a value, not an "invalid" credential. */
    public function test_test_config_returns_false_without_a_key_at_all(): void
    {
        Http::fake(); // No request should even be attempted.

        $this->assertFalse(app(GoogleBooksProvider::class)->testConfig([]));
        $this->assertFalse(app(GoogleBooksProvider::class)->testConfig(['api_key' => '']));
    }

    /** Neither a confirmed-valid nor a confirmed-invalid credential — the check itself didn't complete, so this must not be silently folded into "invalid" (GitHub issue #53's own precedent, already applied the same way for the other TestableMetadataProvider implementations). */
    public function test_test_config_throws_on_an_unexpected_status(): void
    {
        Http::fake([self::SEARCH_API => Http::response(['error' => 'Service unavailable'], 503)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(GoogleBooksProvider::class)->testConfig(['api_key' => 'some-key']);
    }
}
