<?php

namespace Tests\Feature;

use App\Domain\Metadata\Contracts\NameOnlyFallbackProvider;
use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Book\UpcItemDbBookProvider;
use App\Domain\Metadata\Providers\Cd\UpcItemDbCdProvider;
use App\Domain\Metadata\Providers\DvdBluray\UpcItemDbDvdBlurayProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GitHub issue #192: the shared UpcItemDbLookup trait (key mapping,
 * INVALID_UPC/no-match handling, request-failure handling, and the
 * deliberately narrow title-only attribute mapping) exercised through all
 * three concrete providers, plus each provider's own declared identity and
 * NameOnlyFallbackProvider marker. The actual last-resort *gating* (only
 * queried when round 1 finds nothing) is covered separately in
 * NameOnlyFallbackProviderTest.php, since that behavior lives in
 * MetadataImportService, not this trait.
 */
class UpcItemDbProviderTest extends TestCase
{
    private const BASE_URL = 'https://api.upcitemdb.com/prod/trial';

    public function test_each_variant_declares_its_own_key_name_and_media_type(): void
    {
        $book = app(UpcItemDbBookProvider::class);
        $cd = app(UpcItemDbCdProvider::class);
        $dvd = app(UpcItemDbDvdBlurayProvider::class);

        $this->assertSame('book.upcitemdb', $book->key());
        $this->assertSame('cd.upcitemdb', $cd->key());
        $this->assertSame('dvd_bluray.upcitemdb', $dvd->key());
        $this->assertSame('UPCitemdb', $book->name());
        $this->assertSame('book', $book->mediaType());
        $this->assertSame('cd', $cd->mediaType());
        $this->assertSame('dvd_bluray', $dvd->mediaType());
    }

    public function test_every_variant_is_a_name_only_fallback_provider(): void
    {
        $this->assertInstanceOf(NameOnlyFallbackProvider::class, app(UpcItemDbBookProvider::class));
        $this->assertInstanceOf(NameOnlyFallbackProvider::class, app(UpcItemDbCdProvider::class));
        $this->assertInstanceOf(NameOnlyFallbackProvider::class, app(UpcItemDbDvdBlurayProvider::class));
    }

    public function test_every_variant_genuinely_supports_code_lookup_and_needs_no_config(): void
    {
        $provider = app(UpcItemDbBookProvider::class);

        $this->assertTrue($provider->supportsCodeLookup());
        $this->assertSame([], $provider->configFields());
        $this->assertSame('api', $provider->sourceType());
    }

    /** Confirmed live during #192's feasibility study, real ISBN-13 9780747532699. */
    public function test_lookup_by_code_maps_only_the_title_and_first_cover(): void
    {
        Http::fake([self::BASE_URL.'/lookup*' => Http::response([
            'code' => 'OK',
            'total' => 1,
            'items' => [[
                'ean' => '9780747532699',
                'title' => "Harry Potter and the Philosopher's Stone by J. K. Rowling (Hardcover)",
                'isbn' => '9780747532699',
                // Confirmed live: this field genuinely held the author's
                // name, not a publisher — a concrete example of why this
                // trait deliberately never maps it (see its own docblock).
                'publisher' => 'J. K. Rowling',
                'category' => 'Media > Books',
                'images' => ['https://images.BetterWorldBooks.com/074/cover.jpg', 'https://example.com/second.jpg'],
            ]],
        ], 200)]);

        $candidates = app(UpcItemDbBookProvider::class)->lookupByCode('9780747532699');

        $this->assertCount(1, $candidates);
        $this->assertSame('book.upcitemdb', $candidates[0]->providerKey);
        $this->assertSame('9780747532699', $candidates[0]->sourceId);
        $this->assertSame(['title' => "Harry Potter and the Philosopher's Stone by J. K. Rowling (Hardcover)"], $candidates[0]->attributes);
        $this->assertSame(['https://images.BetterWorldBooks.com/074/cover.jpg'], $candidates[0]->coverUrls);
        Http::assertSent(fn ($request) => $request->url() === self::BASE_URL.'/lookup?upc=9780747532699');
    }

    public function test_lookup_by_code_returns_no_candidates_for_a_code_reported_invalid(): void
    {
        // Confirmed live: upcitemdb.com's own way of rejecting a code it
        // won't look up at all — a 200 response, not a non-2xx status.
        Http::fake([self::BASE_URL.'/lookup*' => Http::response(['code' => 'INVALID_UPC', 'message' => 'Not a valid UPC code.'], 200)]);

        $candidates = app(UpcItemDbDvdBlurayProvider::class)->lookupByCode('0000000000000');

        $this->assertSame([], $candidates);
    }

    public function test_lookup_by_code_returns_no_candidates_when_genuinely_not_found(): void
    {
        Http::fake([self::BASE_URL.'/lookup*' => Http::response(['code' => 'OK', 'total' => 0, 'items' => []], 200)]);

        $candidates = app(UpcItemDbCdProvider::class)->lookupByCode('4006680095609');

        $this->assertSame([], $candidates);
    }

    /** GitHub issue #53: a non-2xx (e.g. the documented trial-tier rate limit) is a genuine request failure, not a silent no-match. */
    public function test_lookup_by_code_throws_on_a_non_2xx_response(): void
    {
        Http::fake([self::BASE_URL.'/lookup*' => Http::response(['message' => 'Too Many Requests'], 429)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(UpcItemDbBookProvider::class)->lookupByCode('9780747532699');
    }

    public function test_search_calls_the_search_endpoint_and_maps_the_same_way(): void
    {
        Http::fake([self::BASE_URL.'/search*' => Http::response([
            'code' => 'OK',
            'total' => 1,
            'items' => [['ean' => '9780747532699', 'title' => 'Harry Potter and the Philosopher\'s Stone', 'images' => []]],
        ], 200)]);

        $candidates = app(UpcItemDbBookProvider::class)->search('Harry Potter Philosopher\'s Stone');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), self::BASE_URL.'/search') && $request['s'] === 'Harry Potter Philosopher\'s Stone');
        $this->assertCount(1, $candidates);
        $this->assertSame([], $candidates[0]->coverUrls);
    }

    public function test_search_returns_no_candidates_on_a_failed_request(): void
    {
        Http::fake([self::BASE_URL.'/search*' => Http::response(['message' => 'Too Many Requests'], 429)]);

        $this->assertSame([], app(UpcItemDbCdProvider::class)->search('anything'));
    }
}
