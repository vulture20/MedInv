<?php

namespace Tests\Feature;

use App\Domain\Metadata\Providers\Book\HardcoverProvider;
use App\Models\MetadataPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * HardcoverProvider (GitHub issue #18). lookupByCode()'s fixture mirrors
 * Hardcover's own literal, official "Get Edition Details by ISBN" example
 * query/response shape (docs.hardcover.app/api/graphql/schemas/editions) —
 * high confidence. search()'s fixture is built on Typesense's own
 * well-documented, engine-level {hits: [{document: {...}}]} response
 * convention, since Hardcover's docs list the available fields per
 * query_type but never show a literal example of the `search` field's
 * response envelope — see HardcoverProvider's class docblock. Neither was
 * live-verified against the real API — that requires a personal Hardcover
 * account/token, unavailable in this environment.
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

    private function editionResponse(): array
    {
        return [
            'data' => [
                'editions' => [
                    [
                        'isbn_10' => '0547928227',
                        'isbn_13' => '9780547928227',
                        'pages' => 366,
                        'release_date' => '2012-09-18',
                        'physical_format' => 'paperback',
                        'publisher' => ['name' => 'Houghton Mifflin Harcourt'],
                        'image' => ['url' => 'https://assets.hardcover.app/covers/hobbit-large.jpg'],
                        'book' => [
                            'title' => 'The Hobbit',
                            'description' => 'Bilbo Baggins is a hobbit who enjoys a comfortable life.',
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

    public function test_lookup_by_code_maps_the_first_edition_to_a_candidate(): void
    {
        $this->configureApiKey();
        Http::fake([self::GRAPHQL_URL => Http::response($this->editionResponse(), 200)]);

        $candidate = app(HardcoverProvider::class)->lookupByCode('9780547928227')[0];

        $this->assertSame('The Hobbit', $candidate->attributes['title']);
        $this->assertSame('J.R.R. Tolkien', $candidate->attributes['authors']);
        $this->assertSame('Bilbo Baggins is a hobbit who enjoys a comfortable life.', $candidate->attributes['description']);
        $this->assertSame('Houghton Mifflin Harcourt', $candidate->attributes['publisher']);
        $this->assertSame(366, $candidate->attributes['page_count']);
        $this->assertSame('English', $candidate->attributes['language']);
        $this->assertSame('2012-09-18', $candidate->attributes['release_date']);
        $this->assertSame('paperback', $candidate->attributes['format']);
        $this->assertSame('0547928227', $candidate->attributes['isbn10']);
        $this->assertSame('9780547928227', $candidate->attributes['isbn13']);
        $this->assertSame('9780547928227', $candidate->attributes['ean']);
        $this->assertSame(['https://assets.hardcover.app/covers/hobbit-large.jpg'], $candidate->coverUrls);
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

    public function test_no_candidates_when_the_request_fails(): void
    {
        $this->configureApiKey();
        Http::fake([self::GRAPHQL_URL => Http::response(['error' => 'Unable to verify token'], 401)]);

        $candidates = app(HardcoverProvider::class)->lookupByCode('9780547928227');

        $this->assertSame([], $candidates);
    }

    /** Hasura (what Hardcover's API runs on) returns HTTP 200 with an `errors` array for a query-level failure, e.g. a depth-limit violation. */
    public function test_no_candidates_when_the_response_has_graphql_errors_despite_http_200(): void
    {
        $this->configureApiKey();
        Http::fake([self::GRAPHQL_URL => Http::response([
            'errors' => [['message' => 'query depth limit exceeded']],
        ], 200)]);

        $candidates = app(HardcoverProvider::class)->lookupByCode('9780547928227');

        $this->assertSame([], $candidates);
    }

    public function test_no_candidates_when_no_api_key_is_configured(): void
    {
        Http::fake();

        $candidates = app(HardcoverProvider::class)->lookupByCode('9780547928227');

        $this->assertSame([], $candidates);
        Http::assertNothingSent();
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
        Http::fake([self::GRAPHQL_URL => Http::response([
            'data' => [
                'search' => [
                    'results' => [
                        'hits' => [
                            [
                                'document' => [
                                    'slug' => 'the-hobbit',
                                    'title' => 'The Hobbit',
                                    'author_names' => ['J.R.R. Tolkien'],
                                    'description' => 'A hobbit sets out on an adventure.',
                                    'pages' => 366,
                                    'release_year' => 1937,
                                    'isbns' => ['0547928227', '9780547928227'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], 200)]);

        $candidates = app(HardcoverProvider::class)->search('the hobbit');

        $this->assertCount(1, $candidates);
        $this->assertSame('The Hobbit', $candidates[0]->attributes['title']);
        $this->assertSame('J.R.R. Tolkien', $candidates[0]->attributes['authors']);
        $this->assertSame('1937-01-01', $candidates[0]->attributes['release_date']);
        $this->assertSame('9780547928227', $candidates[0]->attributes['isbn13']);
        $this->assertSame('0547928227', $candidates[0]->attributes['isbn10']);
        $this->assertSame([], $candidates[0]->coverUrls);
    }

    public function test_search_returns_empty_when_the_results_shape_is_unexpected(): void
    {
        $this->configureApiKey();
        // Simulates the documented-but-unverified `results` shape turning out to be different in practice.
        Http::fake([self::GRAPHQL_URL => Http::response([
            'data' => ['search' => ['results' => ['found' => 0]]],
        ], 200)]);

        $candidates = app(HardcoverProvider::class)->search('nonexistent shape');

        $this->assertSame([], $candidates);
    }
}
