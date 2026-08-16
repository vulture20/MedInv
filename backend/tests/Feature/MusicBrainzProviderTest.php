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
    private const RELEASE_API = 'https://musicbrainz.org/ws/2/release*';

    /** Real (trimmed) response shape for `?query=barcode:724385522925&fmt=json`. */
    private function releaseResponse(): array
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

    public function test_lookup_by_code_requests_the_correct_musicbrainz_base_url(): void
    {
        Http::fake([self::RELEASE_API => Http::response($this->releaseResponse(), 200)]);

        app(MusicBrainzProvider::class)->lookupByCode('724385522925');

        // The exact bug this regression-guards: a request to the (wrong,
        // slash-missing) "ws2" path would not match this fake at all and
        // Http::fake()'s catch-all would 500 it, failing the assertion below.
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://musicbrainz.org/ws/2/release?'));
    }

    public function test_lookup_by_code_maps_a_real_release_shape_to_a_candidate(): void
    {
        Http::fake([self::RELEASE_API => Http::response($this->releaseResponse(), 200)]);

        $candidate = app(MusicBrainzProvider::class)->lookupByCode('724385522925')[0];

        $this->assertSame('OK Computer', $candidate->attributes['title']);
        $this->assertSame('Radiohead', $candidate->attributes['artist']);
        $this->assertSame('1997-06-17', $candidate->attributes['release_date']);
        $this->assertSame('724385522925', $candidate->attributes['ean']);
    }

    public function test_search_maps_every_returned_release(): void
    {
        $response = $this->releaseResponse();
        $response['releases'][] = ['id' => 'other-id', 'title' => 'OK Computer (Live)', 'artist-credit' => [['name' => 'Radiohead']]];
        Http::fake([self::RELEASE_API => Http::response($response, 200)]);

        $candidates = app(MusicBrainzProvider::class)->search('radiohead ok computer');

        $this->assertCount(2, $candidates);
        $this->assertSame('OK Computer', $candidates[0]->attributes['title']);
        $this->assertSame('OK Computer (Live)', $candidates[1]->attributes['title']);
    }

    /** search() has no EAN of its own — falls back to the release's own `barcode` field when present. */
    public function test_search_falls_back_to_the_releases_own_barcode_for_ean(): void
    {
        Http::fake([self::RELEASE_API => Http::response($this->releaseResponse(), 200)]);

        $candidate = app(MusicBrainzProvider::class)->search('ok computer')[0];

        $this->assertSame('724385522925', $candidate->attributes['ean']);
    }

    public function test_no_candidates_when_no_release_matches(): void
    {
        Http::fake([self::RELEASE_API => Http::response(['releases' => []], 200)]);

        $candidates = app(MusicBrainzProvider::class)->lookupByCode('000000000000');

        $this->assertSame([], $candidates);
    }

    /** A failed response (e.g. the historical 404) must degrade to "no results", never an exception — a single failing provider must not block the others (briefing 8.3). */
    public function test_no_candidates_when_the_request_fails(): void
    {
        Http::fake([self::RELEASE_API => Http::response(['error' => 'not found'], 404)]);

        $candidates = app(MusicBrainzProvider::class)->lookupByCode('724385522925');

        $this->assertSame([], $candidates);
    }

    public function test_configuration_requires_no_api_key(): void
    {
        $this->assertSame([], app(MusicBrainzProvider::class)->configFields());
    }
}
