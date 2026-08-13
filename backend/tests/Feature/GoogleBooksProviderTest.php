<?php

namespace Tests\Feature;

use App\Domain\Metadata\Providers\Book\GoogleBooksProvider;
use App\Models\MetadataPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GoogleBooksProvider (GitHub issue #20). Fixture shape follows the
 * documented Volumes API response (https://developers.google.com/books/docs/v1/using)
 * — a live fetch during implementation was blocked by the shared-quota 429
 * ("Quota exceeded ... consumer 'project_number:...'") this class's own
 * docblock explains, so unlike OpenLibraryProviderTest these fixtures are
 * schema-accurate but not themselves a live-captured response.
 */
class GoogleBooksProviderTest extends TestCase
{
    use RefreshDatabase;

    private const VOLUMES_API = 'https://www.googleapis.com/books/v1/volumes*';

    private function volumeResponse(): array
    {
        return [
            'kind' => 'books#volumes',
            'totalItems' => 1,
            'items' => [
                [
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
                ],
            ],
        ];
    }

    public function test_lookup_by_code_maps_the_first_item_to_a_candidate(): void
    {
        Http::fake([self::VOLUMES_API => Http::response($this->volumeResponse(), 200)]);

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
    }

    public function test_cover_urls_are_upgraded_from_http_to_https(): void
    {
        Http::fake([self::VOLUMES_API => Http::response($this->volumeResponse(), 200)]);

        $candidate = app(GoogleBooksProvider::class)->lookupByCode('9780593135204')[0];

        $this->assertTrue(collect($candidate->coverUrls)->every(fn (string $url) => str_starts_with($url, 'https://')));
        $this->assertNotEmpty($candidate->coverUrls);
    }

    public function test_no_candidates_when_the_api_has_no_matching_item(): void
    {
        Http::fake([self::VOLUMES_API => Http::response(['kind' => 'books#volumes', 'totalItems' => 0], 200)]);

        $candidates = app(GoogleBooksProvider::class)->lookupByCode('0000000000000');

        $this->assertSame([], $candidates);
    }

    public function test_no_candidates_when_the_request_fails_eg_quota_exceeded(): void
    {
        Http::fake([self::VOLUMES_API => Http::response(['error' => ['code' => 429]], 429)]);

        $candidates = app(GoogleBooksProvider::class)->lookupByCode('9780593135204');

        $this->assertSame([], $candidates);
    }

    public function test_search_maps_every_item(): void
    {
        $response = $this->volumeResponse();
        $response['items'][] = $response['items'][0];
        $response['items'][1]['id'] = 'anotherId';
        Http::fake([self::VOLUMES_API => Http::response($response, 200)]);

        $candidates = app(GoogleBooksProvider::class)->search('project hail mary');

        $this->assertCount(2, $candidates);
        // search() has no known EAN for the query itself, unlike lookupByCode().
        $this->assertNull($candidates[0]->attributes['ean']);
    }

    public function test_configured_api_key_is_sent_as_a_query_parameter(): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'book.google_books',
            'name' => 'Google Books',
            'media_type' => 'book',
            'enabled' => true,
            'config' => ['api_key' => 'secret-key-123'],
        ]);
        Http::fake([self::VOLUMES_API => Http::response($this->volumeResponse(), 200)]);

        app(GoogleBooksProvider::class)->lookupByCode('9780593135204');

        Http::assertSent(fn ($request) => $request['key'] === 'secret-key-123');
    }

    public function test_works_without_any_configured_api_key(): void
    {
        Http::fake([self::VOLUMES_API => Http::response($this->volumeResponse(), 200)]);

        $candidates = app(GoogleBooksProvider::class)->lookupByCode('9780593135204');

        $this->assertNotEmpty($candidates);
        Http::assertSent(fn ($request) => ! isset($request['key']));
    }
}
