<?php

namespace Tests\Feature;

use App\Domain\Metadata\Providers\Book\OpenLibraryProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpenLibraryProvider::lookupByCode()/mapToCandidate() (GitHub issue #28):
 * format/isbn10 were never read at all, and multi-author editions could lose
 * their authors. All fixture payloads below are trimmed copies of real,
 * live-verified responses (fetched against EAN/ISBN-13 9783823700166, the
 * ISBN reported in the issue, and 9780593135204 as a single-author control).
 */
class OpenLibraryProviderTest extends TestCase
{
    private const BOOKS_API = 'https://openlibrary.org/api/books*';

    private const EDITIONS_API = 'https://openlibrary.org/isbn/*.json';

    /** jscmd=data response for 9783823700166 — a real edition with FOUR authors, where the Books API omits `authors` entirely. */
    private function booksApiResponseWithoutAuthors(): array
    {
        return [
            'ISBN:9783823700166' => [
                'url' => 'http://openlibrary.org/books/OL12973299M',
                'key' => '/books/OL12973299M',
                'title' => 'Kaufmännisches Rechnen für berufliche Schulen. (Lernmaterialien)',
                'number_of_pages' => 264,
                'identifiers' => [
                    'isbn_10' => ['3823700162'],
                    'isbn_13' => ['9783823700166'],
                ],
                'publishers' => [['name' => 'Stam']],
                'publish_date' => 'June 1, 2001',
                'cover' => [
                    'small' => 'https://covers.openlibrary.org/b/id/3274427-S.jpg',
                    'medium' => 'https://covers.openlibrary.org/b/id/3274427-M.jpg',
                    'large' => 'https://covers.openlibrary.org/b/id/3274427-L.jpg',
                ],
                // Deliberately no 'authors' key — this is what the Books API actually returns for this ISBN.
            ],
        ];
    }

    /** Raw Editions API record for the same ISBN — has physical_format and the raw (unresolved) author keys the Books API dropped. */
    private function editionsApiResponse(): array
    {
        return [
            'title' => 'Kaufmännisches Rechnen für berufliche Schulen. (Lernmaterialien)',
            'authors' => [
                ['key' => '/authors/OL4141728A'],
                ['key' => '/authors/OL4141729A'],
                ['key' => '/authors/OL4141730A'],
                ['key' => '/authors/OL4141731A'],
            ],
            'publish_date' => 'June 1, 2001',
            'publishers' => ['Stam'],
            'physical_format' => 'Paperback',
            'isbn_13' => ['9783823700166'],
            'isbn_10' => ['3823700162'],
            'number_of_pages' => 264,
        ];
    }

    /** jscmd=data response for 9780593135204 — a single-author edition where the Books API resolves `authors` fine on its own. */
    private function booksApiResponseWithAuthors(): array
    {
        return [
            'ISBN:9780593135204' => [
                'url' => 'http://openlibrary.org/books/OL30036715M/Project_Hail_Mary',
                'key' => '/books/OL30036715M',
                'title' => 'Project Hail Mary',
                'authors' => [
                    ['url' => 'http://openlibrary.org/authors/OL7234434A/Andy_Weir', 'name' => 'Andy Weir'],
                ],
                'number_of_pages' => 496,
                'identifiers' => [
                    'isbn_10' => ['0593135202'],
                    'isbn_13' => ['9780593135204'],
                ],
                'publishers' => [['name' => 'Ballantine Books']],
                'publish_date' => 'May 04, 2021',
            ],
        ];
    }

    /**
     * Regression test for "the downloaded cover is much too small": the
     * Books API's `cover` object is ordered small/medium/large (confirmed
     * live, see the fixture above) — cover_urls[0] (what
     * CoverDownloadService::download() actually fetches, via
     * cover_urls[0] in CapturePage.tsx) must be the *large* URL despite
     * that source ordering, not whichever came first in the response.
     */
    public function test_cover_urls_puts_the_large_cover_first_despite_the_apis_small_first_ordering(): void
    {
        Http::fake([
            self::BOOKS_API => Http::response($this->booksApiResponseWithoutAuthors(), 200),
            self::EDITIONS_API => Http::response($this->editionsApiResponse(), 200),
            'https://openlibrary.org/authors/*.json' => Http::response(['name' => 'Ignored here'], 200),
        ]);

        $candidate = app(OpenLibraryProvider::class)->lookupByCode('9783823700166')[0];

        $this->assertSame('https://covers.openlibrary.org/b/id/3274427-L.jpg', $candidate->coverUrls[0]);
    }

    public function test_format_and_isbn10_are_read_from_the_editions_api(): void
    {
        Http::fake([
            self::BOOKS_API => Http::response($this->booksApiResponseWithoutAuthors(), 200),
            self::EDITIONS_API => Http::response($this->editionsApiResponse(), 200),
            'https://openlibrary.org/authors/*.json' => Http::response(['name' => 'Ignored here'], 200),
        ]);

        $candidate = app(OpenLibraryProvider::class)->lookupByCode('9783823700166')[0];

        $this->assertSame('Paperback', $candidate->attributes['format']);
        $this->assertSame('3823700162', $candidate->attributes['isbn10']);
        $this->assertSame('9783823700166', $candidate->attributes['isbn13']);
    }

    public function test_authors_fall_back_to_resolving_editions_api_author_references_when_the_books_api_omits_them(): void
    {
        Http::fake([
            self::BOOKS_API => Http::response($this->booksApiResponseWithoutAuthors(), 200),
            self::EDITIONS_API => Http::response($this->editionsApiResponse(), 200),
            'https://openlibrary.org/authors/OL4141728A.json' => Http::response(['name' => 'Manfred Adams'], 200),
            'https://openlibrary.org/authors/OL4141729A.json' => Http::response(['name' => 'Josef Oligschläger'], 200),
            'https://openlibrary.org/authors/OL4141730A.json' => Http::response(['name' => 'Hermann Schenkelberg'], 200),
            'https://openlibrary.org/authors/OL4141731A.json' => Http::response(['name' => 'H. Wamper'], 200),
        ]);

        $candidate = app(OpenLibraryProvider::class)->lookupByCode('9783823700166')[0];

        $this->assertSame('Manfred Adams, Josef Oligschläger, Hermann Schenkelberg, H. Wamper', $candidate->attributes['authors']);
    }

    public function test_authors_already_resolved_by_the_books_api_are_used_as_is_without_resolving_references(): void
    {
        Http::fake([
            self::BOOKS_API => Http::response($this->booksApiResponseWithAuthors(), 200),
            self::EDITIONS_API => Http::response([
                'title' => 'Project Hail Mary',
                'authors' => [['key' => '/authors/OL7234434A']],
                'physical_format' => 'Hardcover',
            ], 200),
        ]);

        $candidate = app(OpenLibraryProvider::class)->lookupByCode('9780593135204')[0];

        $this->assertSame('Andy Weir', $candidate->attributes['authors']);
        // No /authors/OL7234434A.json call needed — the Books API already had a name.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/authors/OL7234434A.json'));
    }

    public function test_missing_editions_api_response_degrades_gracefully(): void
    {
        Http::fake([
            self::BOOKS_API => Http::response($this->booksApiResponseWithAuthors(), 200),
            self::EDITIONS_API => Http::response([], 404),
        ]);

        $candidate = app(OpenLibraryProvider::class)->lookupByCode('9780593135204')[0];

        $this->assertNull($candidate->attributes['format']);
        $this->assertSame('Andy Weir', $candidate->attributes['authors']);
        $this->assertSame('0593135202', $candidate->attributes['isbn10']);
    }

    public function test_no_candidates_when_the_books_api_has_no_entry(): void
    {
        Http::fake([self::BOOKS_API => Http::response([], 200)]);

        $candidates = app(OpenLibraryProvider::class)->lookupByCode('0000000000000');

        $this->assertSame([], $candidates);
    }
}
