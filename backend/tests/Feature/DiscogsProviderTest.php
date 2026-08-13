<?php

namespace Tests\Feature;

use App\Domain\Metadata\Providers\Cd\DiscogsProvider;
use App\Models\MetadataPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DiscogsProvider (GitHub issue #22). Fixtures are trimmed copies of real,
 * live-verified responses (unauthenticated — Discogs' API works without
 * any token, see this provider's docblock) for barcode 724385522925
 * ("Radiohead - OK Computer") and a free-text search for the same.
 */
class DiscogsProviderTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH_API = 'https://api.discogs.com/database/search*';

    private const RELEASE_API = 'https://api.discogs.com/releases/*';

    /** Real (trimmed) `?barcode=724385522925&type=release` response — note the empty cover_image/thumb and combined "Artist - Title" field, confirmed live to always be this way for unauthenticated search results. */
    private function searchResponse(): array
    {
        return [
            'pagination' => ['page' => 1, 'pages' => 2, 'items' => 67],
            'results' => [
                [
                    'id' => 10559709,
                    'title' => 'Radiohead - OK Computer',
                    'year' => null,
                    'country' => 'US',
                    'format' => ['CD', 'Album', 'Reissue'],
                    'cover_image' => '',
                    'thumb' => '',
                ],
            ],
        ];
    }

    /** Real (trimmed) `GET /releases/10559709` response for the same release. */
    private function releaseResponse(): array
    {
        return [
            'id' => 10559709,
            'title' => 'OK Computer',
            'artists_sort' => 'Radiohead',
            'released' => '1997-07-01',
            'notes' => "Audio recordings made using the Canned Applause? Mobile.\nMastered at Abbey Road.",
            'formats' => [
                ['name' => 'CD', 'qty' => '1', 'descriptions' => ['Album']],
            ],
            'images' => [
                ['type' => 'primary', 'uri' => 'https://i.discogs.com/hIw4ia_ID1C8cpC3OWnjIYGZyJgqFGPoeVdzVpHq3Rk.jpeg'],
                ['type' => 'secondary', 'uri' => 'https://i.discogs.com/other-image.jpeg'],
            ],
        ];
    }

    public function test_lookup_by_code_fetches_the_full_release_and_maps_it_to_a_candidate(): void
    {
        Http::fake([
            self::RELEASE_API => Http::response($this->releaseResponse(), 200),
            self::SEARCH_API => Http::response($this->searchResponse(), 200),
        ]);

        $candidate = app(DiscogsProvider::class)->lookupByCode('724385522925')[0];

        $this->assertSame('OK Computer', $candidate->attributes['title']);
        $this->assertSame('Radiohead', $candidate->attributes['artist']);
        $this->assertSame("Audio recordings made using the Canned Applause? Mobile.\nMastered at Abbey Road.", $candidate->attributes['description']);
        $this->assertSame('CD', $candidate->attributes['medium']);
        $this->assertSame(1, $candidate->attributes['disc_count']);
        $this->assertSame('1997-07-01', $candidate->attributes['release_date']);
        $this->assertSame('724385522925', $candidate->attributes['ean']);
        $this->assertSame([
            'https://i.discogs.com/hIw4ia_ID1C8cpC3OWnjIYGZyJgqFGPoeVdzVpHq3Rk.jpeg',
            'https://i.discogs.com/other-image.jpeg',
        ], $candidate->coverUrls);
    }

    /** @return array<string, array{0: ?string, 1: ?string}> [released, expected release_date] */
    public static function releasedDateCases(): array
    {
        return [
            'full ISO date, unchanged' => ['1997-07-01', '1997-07-01'],
            'bare year' => ['2006', '2006-01-01'],
            'year + month, unknown day' => ['1980-01-00', '1980-01-01'],
            'year only, month and day both unknown' => ['1980-00-00', '1980-01-01'],
            'absent entirely' => [null, null],
        ];
    }

    /**
     * Regression test for "the release year wasn't imported correctly":
     * `released` is free-form and inconsistently populated — confirmed
     * live across real releases: a full ISO date, a bare year, a
     * year-with-unknown-month/day, or absent. All four normalize to a
     * real date instead of the raw (sometimes unparseable-as-a-clean-date)
     * string being stored directly.
     */
    #[DataProvider('releasedDateCases')]
    public function test_release_date_normalizes_every_shape_discogs_actually_returns(?string $raw, ?string $expected): void
    {
        $response = $this->releaseResponse();
        $response['released'] = $raw;
        Http::fake([
            self::RELEASE_API => Http::response($response, 200),
            self::SEARCH_API => Http::response($this->searchResponse(), 200),
        ]);

        $candidate = app(DiscogsProvider::class)->lookupByCode('724385522925')[0];

        $this->assertSame($expected, $candidate->attributes['release_date']);
    }

    public function test_no_candidates_when_no_search_result_matches(): void
    {
        Http::fake([self::SEARCH_API => Http::response(['results' => []], 200)]);

        $candidates = app(DiscogsProvider::class)->lookupByCode('0000000000000');

        $this->assertSame([], $candidates);
    }

    public function test_no_candidates_when_the_search_request_fails(): void
    {
        Http::fake([self::SEARCH_API => Http::response(['message' => 'error'], 500)]);

        $candidates = app(DiscogsProvider::class)->lookupByCode('724385522925');

        $this->assertSame([], $candidates);
    }

    /** The search hit's own data is still usable if the extra detail fetch fails — no cover/description, but not an empty result either. */
    public function test_falls_back_to_the_search_result_when_the_full_release_fetch_fails(): void
    {
        Http::fake([
            self::RELEASE_API => Http::response(['message' => 'Not found'], 404),
            self::SEARCH_API => Http::response($this->searchResponse(), 200),
        ]);

        $candidate = app(DiscogsProvider::class)->lookupByCode('724385522909')[0];

        $this->assertSame('OK Computer', $candidate->attributes['title']);
        $this->assertSame('Radiohead', $candidate->attributes['artist']);
        $this->assertSame('CD', $candidate->attributes['medium']);
        $this->assertSame([], $candidate->coverUrls);
    }

    public function test_search_maps_every_result_without_fetching_full_release_details(): void
    {
        $response = $this->searchResponse();
        $response['results'][] = ['id' => 4410273, 'title' => 'Radiohead - OK Computer', 'format' => ['CD']];
        Http::fake([self::SEARCH_API => Http::response($response, 200)]);

        $candidates = app(DiscogsProvider::class)->search('radiohead ok computer');

        $this->assertCount(2, $candidates);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/releases/'));
    }

    public function test_search_splits_artist_and_title_from_the_combined_field(): void
    {
        Http::fake([self::SEARCH_API => Http::response($this->searchResponse(), 200)]);

        $candidate = app(DiscogsProvider::class)->search('radiohead ok computer')[0];

        $this->assertSame('Radiohead', $candidate->attributes['artist']);
        $this->assertSame('OK Computer', $candidate->attributes['title']);
    }

    public function test_search_result_without_a_dash_uses_the_whole_string_as_the_title(): void
    {
        Http::fake([self::SEARCH_API => Http::response([
            'results' => [['id' => 1, 'title' => 'Untitled Release', 'format' => ['CD']]],
        ], 200)]);

        $candidate = app(DiscogsProvider::class)->search('untitled')[0];

        $this->assertNull($candidate->attributes['artist']);
        $this->assertSame('Untitled Release', $candidate->attributes['title']);
    }

    public function test_configured_api_key_is_sent_as_a_discogs_authorization_header(): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'cd.discogs',
            'name' => 'Discogs',
            'media_type' => 'cd',
            'enabled' => true,
            'config' => ['api_key' => 'secret-token-123'],
        ]);
        Http::fake([
            self::RELEASE_API => Http::response($this->releaseResponse(), 200),
            self::SEARCH_API => Http::response($this->searchResponse(), 200),
        ]);

        app(DiscogsProvider::class)->lookupByCode('724385522925');

        Http::assertSent(fn ($request) => $request->header('Authorization')[0] === 'Discogs token=secret-token-123');
    }

    public function test_works_without_any_configured_api_key(): void
    {
        Http::fake([
            self::RELEASE_API => Http::response($this->releaseResponse(), 200),
            self::SEARCH_API => Http::response($this->searchResponse(), 200),
        ]);

        $candidates = app(DiscogsProvider::class)->lookupByCode('724385522925');

        $this->assertNotEmpty($candidates);
        Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
    }
}
