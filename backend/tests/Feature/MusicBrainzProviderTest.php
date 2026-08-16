<?php

namespace Tests\Feature;

use App\Domain\Metadata\Providers\Cd\MusicBrainzProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MusicBrainzProvider (briefing 8.2 — CD). GitHub issue #48's investigation
 * found the base URL had been `/ws2/release` (missing the slash between
 * `ws` and `2`) ever since this provider was written — live-confirmed to
 * 404 against the real API, which lookupByCode()/search() silently treat
 * as "no results" rather than an error, so this provider had never
 * actually returned a real candidate. This file didn't exist before that
 * fix; it exists now specifically so a regression to the wrong URL fails a
 * test instead of silently reintroducing the same bug.
 */
class MusicBrainzProviderTest extends TestCase
{
    /** The barcode/free-text search endpoint — `?query=...`. */
    private const SEARCH_API = 'https://musicbrainz.org/ws/2/release?*';

    /** The direct by-MBID lookup endpoint (`inc=recordings`, GitHub issue #48's track-listing fetch) — `/ws/2/release/{id}`. */
    private const RELEASE_DETAIL_API = 'https://musicbrainz.org/ws/2/release/*';

    /** Real (trimmed) response shape for `?query=barcode:724385522925&fmt=json`. */
    private function searchResponse(): array
    {
        return [
            'releases' => [
                [
                    'id' => '4b3d18cc-8937-36f4-8de0-481088be58e6',
                    'title' => 'OK Computer',
                    'artist-credit' => [['name' => 'Radiohead']],
                    'date' => '1997-06-17',
                    'barcode' => '724385522925',
                ],
            ],
        ];
    }

    /** Real (trimmed) response shape for `GET /ws/2/release/{id}?fmt=json&inc=recordings`. */
    private function releaseDetailResponse(): array
    {
        return [
            'media' => [
                [
                    'tracks' => [
                        ['number' => '1', 'title' => 'Airbag', 'length' => 284400],
                        ['number' => '2', 'title' => 'Paranoid Android', 'length' => 383493],
                        // A real track with no known length — kept, but with a null duration.
                        ['number' => '3', 'title' => 'Subterranean Homesick Alien'],
                    ],
                ],
            ],
        ];
    }

    public function test_lookup_by_code_requests_the_correct_musicbrainz_base_url(): void
    {
        Http::fake([self::SEARCH_API => Http::response($this->searchResponse(), 200), self::RELEASE_DETAIL_API => Http::response([], 200)]);

        app(MusicBrainzProvider::class)->lookupByCode('724385522925');

        // The exact bug this regression-guards: a request to the (wrong,
        // slash-missing) "ws2" path would not match this fake at all and
        // Http::fake()'s catch-all would 500 it, failing the assertion below.
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://musicbrainz.org/ws/2/release?'));
    }

    public function test_lookup_by_code_maps_a_real_release_shape_to_a_candidate(): void
    {
        Http::fake([self::SEARCH_API => Http::response($this->searchResponse(), 200), self::RELEASE_DETAIL_API => Http::response([], 200)]);

        $candidate = app(MusicBrainzProvider::class)->lookupByCode('724385522925')[0];

        $this->assertSame('OK Computer', $candidate->attributes['title']);
        $this->assertSame('Radiohead', $candidate->attributes['artist']);
        $this->assertSame('1997-06-17', $candidate->attributes['release_date']);
        $this->assertSame('724385522925', $candidate->attributes['ean']);
    }

    /**
     * GitHub issue #48. Track data isn't part of the search response at all
     * (confirmed live — see this provider's docblock), so lookupByCode()
     * makes a second, direct-by-MBID request with `inc=recordings` for it.
     */
    public function test_lookup_by_code_fetches_and_maps_the_tracklist(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResponse(), 200),
            self::RELEASE_DETAIL_API => Http::response($this->releaseDetailResponse(), 200),
        ]);

        $candidate = app(MusicBrainzProvider::class)->lookupByCode('724385522925')[0];

        $this->assertSame([
            ['position' => '1', 'title' => 'Airbag', 'duration_seconds' => 284],
            ['position' => '2', 'title' => 'Paranoid Android', 'duration_seconds' => 383],
            ['position' => '3', 'title' => 'Subterranean Homesick Alien', 'duration_seconds' => null],
        ], $candidate->attributes['tracks']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/ws/2/release/4b3d18cc-8937-36f4-8de0-481088be58e6') && $request['inc'] === 'recordings');
    }

    /**
     * No 'runtime_seconds'/'runtime_computed' here on purpose — see this
     * provider's docblock: a runtime is only ever derived centrally
     * (MediaItemService::create()) from whichever `tracks` value is
     * finally chosen, not reported independently by a provider.
     */
    public function test_lookup_by_code_does_not_set_a_runtime_of_its_own(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResponse(), 200),
            self::RELEASE_DETAIL_API => Http::response($this->releaseDetailResponse(), 200),
        ]);

        $candidate = app(MusicBrainzProvider::class)->lookupByCode('724385522925')[0];

        $this->assertArrayNotHasKey('runtime_seconds', $candidate->attributes);
        $this->assertArrayNotHasKey('runtime_computed', $candidate->attributes);
    }

    /** Bounds the number of extra by-MBID lookups a single lookupByCode() can make — MusicBrainz's unauthenticated rate limit is a strict 1 request/second. */
    public function test_lookup_by_code_only_fetches_tracks_for_the_configured_maximum_of_releases(): void
    {
        $manyReleases = collect(range(1, 6))->map(fn (int $i) => ['id' => "release-{$i}", 'title' => 'OK Computer'])->all();
        Http::fake([
            self::SEARCH_API => Http::response(['releases' => $manyReleases], 200),
            self::RELEASE_DETAIL_API => Http::response($this->releaseDetailResponse(), 200),
        ]);

        $candidates = app(MusicBrainzProvider::class)->lookupByCode('724385522925');

        $withTracks = collect($candidates)->filter(fn ($c) => $c->attributes['tracks'] !== null);
        $withoutTracks = collect($candidates)->filter(fn ($c) => $c->attributes['tracks'] === null);
        $this->assertCount(3, $withTracks);
        $this->assertCount(3, $withoutTracks);
    }

    public function test_search_maps_every_returned_release(): void
    {
        $response = $this->searchResponse();
        $response['releases'][] = ['id' => 'other-id', 'title' => 'OK Computer (Live)', 'artist-credit' => [['name' => 'Radiohead']]];
        Http::fake([self::SEARCH_API => Http::response($response, 200)]);

        $candidates = app(MusicBrainzProvider::class)->search('radiohead ok computer');

        $this->assertCount(2, $candidates);
        $this->assertSame('OK Computer', $candidates[0]->attributes['title']);
        $this->assertSame('OK Computer (Live)', $candidates[1]->attributes['title']);
    }

    /** search() deliberately stays single-call, same reasoning as DiscogsProvider::search() — no track-fetching enrichment. */
    public function test_search_does_not_fetch_tracks(): void
    {
        Http::fake([self::SEARCH_API => Http::response($this->searchResponse(), 200)]);

        $candidate = app(MusicBrainzProvider::class)->search('ok computer')[0];

        $this->assertNull($candidate->attributes['tracks']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/ws/2/release/'));
    }

    /** search() has no EAN of its own — falls back to the release's own `barcode` field when present. */
    public function test_search_falls_back_to_the_releases_own_barcode_for_ean(): void
    {
        Http::fake([self::SEARCH_API => Http::response($this->searchResponse(), 200)]);

        $candidate = app(MusicBrainzProvider::class)->search('ok computer')[0];

        $this->assertSame('724385522925', $candidate->attributes['ean']);
    }

    public function test_no_candidates_when_no_release_matches(): void
    {
        Http::fake([self::SEARCH_API => Http::response(['releases' => []], 200)]);

        $candidates = app(MusicBrainzProvider::class)->lookupByCode('000000000000');

        $this->assertSame([], $candidates);
    }

    /** A failed response (e.g. the historical 404) must degrade to "no results", never an exception — a single failing provider must not block the others (briefing 8.3). */
    public function test_no_candidates_when_the_request_fails(): void
    {
        Http::fake([self::SEARCH_API => Http::response(['error' => 'not found'], 404)]);

        $candidates = app(MusicBrainzProvider::class)->lookupByCode('724385522925');

        $this->assertSame([], $candidates);
    }

    /** A failed track-detail fetch is best-effort — must not fail the whole candidate, just leave it without tracks. */
    public function test_a_failed_track_detail_fetch_still_returns_the_candidate_without_tracks(): void
    {
        Http::fake([
            self::SEARCH_API => Http::response($this->searchResponse(), 200),
            self::RELEASE_DETAIL_API => Http::response(['error' => 'not found'], 404),
        ]);

        $candidate = app(MusicBrainzProvider::class)->lookupByCode('724385522925')[0];

        $this->assertSame('OK Computer', $candidate->attributes['title']);
        $this->assertSame([], $candidate->attributes['tracks']);
    }

    public function test_configuration_requires_no_api_key(): void
    {
        $this->assertSame([], app(MusicBrainzProvider::class)->configFields());
    }
}
