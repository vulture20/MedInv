<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Book\HardcoverProvider;
use App\Models\MetadataPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * HardcoverProvider (GitHub issue #18). All fixtures below are trimmed
 * copies of real, live-verified responses (a real Hardcover account's
 * token, EAN 9780547928227 "The Hobbit" for lookupByCode(), a free-text
 * search for "the hobbit" for search()) — see HardcoverProvider's
 * docblock for what that live testing found that the documentation alone
 * didn't show (search()'s `image`/`release_date` fields, HTML-formatted
 * descriptions).
 */
class HardcoverProviderTest extends TestCase
{
    use RefreshDatabase;

    private const GRAPHQL_URL = 'https://api.hardcover.app/v1/graphql';

    private function configureApiKey(string $key = 'test-token-abc'): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'book.hardcover',
            'name' => 'Hardcover',
            'media_type' => 'book',
            'enabled' => true,
            'config' => ['api_key' => $key],
        ]);
    }

    /** Real (trimmed) response for `editions(where: {isbn_13: {_eq: "9780547928227"}})`. */
    private function editionResponse(): array
    {
        return [
            'data' => [
                'editions' => [
                    [
                        'isbn_10' => '054792822X',
                        'isbn_13' => '9780547928227',
                        'pages' => 300,
                        'release_date' => '2012-09-18',
                        'physical_format' => null,
                        'publisher' => ['name' => 'Houghton Mifflin Harcourt'],
                        'image' => ['url' => 'https://assets.hardcover.app/edition/18521437/a35044c6817357b701da3bcac5dce295244c32ba.jpeg'],
                        'book' => [
                            'title' => 'The Hobbit, or There and Back Again',
                            'description' => '<i>"In a hole in the ground there lived a hobbit." </i>So begins one of the most beloved tales.',
                            'contributions' => [
                                ['author' => ['name' => 'J.R.R. Tolkien']],
                            ],
                        ],
                        'language' => ['language' => 'English'],
                    ],
                ],
            ],
        ];
    }

    /** Real (trimmed) `search(query: "the hobbit", query_type: "Book")` response — one document. */
    private function searchResponse(): array
    {
        return [
            'data' => [
                'search' => [
                    'results' => [
                        'found' => 136,
                        'hits' => [
                            [
                                'document' => [
                                    'id' => '2142692',
                                    'slug' => 'the-hobbit-part-two',
                                    'title' => 'The Hobbit, Part Two',
                                    'author_names' => ['J.R.R. Tolkien', 'David Wyatt'],
                                    'description' => "The Hobbit is a tale of high adventure, undertaken by a company of dwarves in search of dragon-guarded gold.\r\n\r\nEncounters with trolls, and more.",
                                    'pages' => 176,
                                    'release_date' => '1937-09-21',
                                    'release_year' => 1937,
                                    'isbns' => ['0007926677', '9780007926671'],
                                    'image' => [
                                        'url' => 'https://assets.hardcover.app/external_data/1540814/0e0218d7fdfa5ff50270b018ba514b66cdb18e68.jpeg',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_lookup_by_code_maps_the_first_edition_to_a_candidate(): void
    {
        $this->configureApiKey();
        Http::fake([self::GRAPHQL_URL => Http::response($this->editionResponse(), 200)]);

        $candidate = app(HardcoverProvider::class)->lookupByCode('9780547928227')[0];

        $this->assertSame('The Hobbit, or There and Back Again', $candidate->attributes['title']);
        $this->assertSame('J.R.R. Tolkien', $candidate->attributes['authors']);
        $this->assertSame('Houghton Mifflin Harcourt', $candidate->attributes['publisher']);
        $this->assertSame(300, $candidate->attributes['page_count']);
        $this->assertSame('English', $candidate->attributes['language']);
        $this->assertSame('2012-09-18', $candidate->attributes['release_date']);
        $this->assertNull($candidate->attributes['format']);
        $this->assertSame('054792822X', $candidate->attributes['isbn10']);
        $this->assertSame('9780547928227', $candidate->attributes['isbn13']);
        $this->assertSame('9780547928227', $candidate->attributes['ean']);
        $this->assertSame(['https://assets.hardcover.app/edition/18521437/a35044c6817357b701da3bcac5dce295244c32ba.jpeg'], $candidate->coverUrls);
    }

    /** Regression test: real responses return the description as raw HTML — confirmed live. */
    public function test_html_in_the_description_is_stripped_to_plain_text(): void
    {
        $this->configureApiKey();
        Http::fake([self::GRAPHQL_URL => Http::response($this->editionResponse(), 200)]);

        $candidate = app(HardcoverProvider::class)->lookupByCode('9780547928227')[0];

        $this->assertSame(
            '"In a hole in the ground there lived a hobbit." So begins one of the most beloved tales.',
            $candidate->attributes['description']
        );
    }

    public function test_authors_are_joined_from_multiple_contributions(): void
    {
        $this->configureApiKey();
        $response = $this->editionResponse();
        $response['data']['editions'][0]['book']['contributions'][] = ['author' => ['name' => 'Christopher Tolkien']];
        Http::fake([self::GRAPHQL_URL => Http::response($response, 200)]);

        $candidate = app(HardcoverProvider::class)->lookupByCode('9780547928227')[0];

        $this->assertSame('J.R.R. Tolkien, Christopher Tolkien', $candidate->attributes['authors']);
    }

    public function test_no_candidates_when_no_edition_matches(): void
    {
        $this->configureApiKey();
        Http::fake([self::GRAPHQL_URL => Http::response(['data' => ['editions' => []]], 200)]);

        $candidates = app(HardcoverProvider::class)->lookupByCode('0000000000000');

        $this->assertSame([], $candidates);
    }

    /** GitHub issue #53: a failed request is reported as 'failed', not silently as 'no_match'. */
    public function test_lookup_by_code_throws_when_the_request_fails(): void
    {
        $this->configureApiKey();
        // The real, documented (and live-confirmed) shape of an unauthenticated/invalid-token response.
        Http::fake([self::GRAPHQL_URL => Http::response(['error' => 'Unable to verify token'], 401)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(HardcoverProvider::class)->lookupByCode('9780547928227');
    }

    /** Hasura (what Hardcover's API runs on) returns HTTP 200 with an `errors` array for a query-level failure, e.g. a depth-limit violation — still 'failed' (#53), not 'no_match'. */
    public function test_lookup_by_code_throws_when_the_response_has_graphql_errors_despite_http_200(): void
    {
        $this->configureApiKey();
        Http::fake([self::GRAPHQL_URL => Http::response([
            'errors' => [['message' => 'query depth limit exceeded']],
        ], 200)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(HardcoverProvider::class)->lookupByCode('9780547928227');
    }

    /** GitHub issue #53: a missing required api_key is a misconfiguration, reported as 'failed' — the exact "falsch konfigurierter API-Key" example the issue names. */
    public function test_lookup_by_code_throws_when_no_api_key_is_configured(): void
    {
        Http::fake();

        $this->expectException(MetadataProviderRequestException::class);
        try {
            app(HardcoverProvider::class)->lookupByCode('9780547928227');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_the_stored_key_is_sent_with_a_bearer_prefix(): void
    {
        $this->configureApiKey('raw-token-xyz');
        Http::fake([self::GRAPHQL_URL => Http::response($this->editionResponse(), 200)]);

        app(HardcoverProvider::class)->lookupByCode('9780547928227');

        Http::assertSent(fn ($request) => $request->header('Authorization')[0] === 'Bearer raw-token-xyz');
    }

    /** Defends against an admin pasting Hardcover's own displayed "Bearer eyJ..." string verbatim instead of stripping it first — see HardcoverProvider's docblock. */
    public function test_a_stored_key_that_already_has_a_bearer_prefix_is_not_doubled(): void
    {
        $this->configureApiKey('Bearer raw-token-xyz');
        Http::fake([self::GRAPHQL_URL => Http::response($this->editionResponse(), 200)]);

        app(HardcoverProvider::class)->lookupByCode('9780547928227');

        Http::assertSent(fn ($request) => $request->header('Authorization')[0] === 'Bearer raw-token-xyz');
    }

    public function test_search_maps_documents_from_the_typesense_style_hits_envelope(): void
    {
        $this->configureApiKey();
        Http::fake([self::GRAPHQL_URL => Http::response($this->searchResponse(), 200)]);

        $candidates = app(HardcoverProvider::class)->search('the hobbit');

        $this->assertCount(1, $candidates);
        $candidate = $candidates[0];
        $this->assertSame('2142692', $candidate->sourceId);
        $this->assertSame('The Hobbit, Part Two', $candidate->attributes['title']);
        $this->assertSame('J.R.R. Tolkien, David Wyatt', $candidate->attributes['authors']);
        $this->assertSame(176, $candidate->attributes['page_count']);
        $this->assertSame('9780007926671', $candidate->attributes['isbn13']);
        $this->assertSame('0007926677', $candidate->attributes['isbn10']);
    }

    /** Regression test: search() results carry a real `image` field even though it isn't in Hardcover's documented Book search fields list — confirmed live. */
    public function test_search_results_use_the_undocumented_image_field_as_the_cover(): void
    {
        $this->configureApiKey();
        Http::fake([self::GRAPHQL_URL => Http::response($this->searchResponse(), 200)]);

        $candidate = app(HardcoverProvider::class)->search('the hobbit')[0];

        $this->assertSame(
            ['https://assets.hardcover.app/external_data/1540814/0e0218d7fdfa5ff50270b018ba514b66cdb18e68.jpeg'],
            $candidate->coverUrls
        );
    }

    /** Regression test: search() documents carry a real `release_date`, contrary to the documented field list — preferred over synthesizing one from `release_year`. */
    public function test_search_prefers_the_real_release_date_over_a_synthesized_one(): void
    {
        $this->configureApiKey();
        Http::fake([self::GRAPHQL_URL => Http::response($this->searchResponse(), 200)]);

        $candidate = app(HardcoverProvider::class)->search('the hobbit')[0];

        $this->assertSame('1937-09-21', $candidate->attributes['release_date']);
    }

    public function test_search_falls_back_to_a_synthesized_release_date_when_none_is_present(): void
    {
        $this->configureApiKey();
        $response = $this->searchResponse();
        unset($response['data']['search']['results']['hits'][0]['document']['release_date']);
        Http::fake([self::GRAPHQL_URL => Http::response($response, 200)]);

        $candidate = app(HardcoverProvider::class)->search('the hobbit')[0];

        $this->assertSame('1937-01-01', $candidate->attributes['release_date']);
    }

    public function test_search_returns_empty_when_the_results_shape_is_unexpected(): void
    {
        $this->configureApiKey();
        Http::fake([self::GRAPHQL_URL => Http::response([
            'data' => ['search' => ['results' => ['found' => 0]]],
        ], 200)]);

        $candidates = app(HardcoverProvider::class)->search('nonexistent shape');

        $this->assertSame([], $candidates);
    }
}
