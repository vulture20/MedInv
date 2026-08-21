<?php

namespace Tests\Feature;

use App\Domain\Metadata\Providers\DvdBluray\TmdbProvider;
use App\Models\MetadataPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GitHub issue #157: TMDB as a DVD/Blu-ray metadata provider — the first
 * one where GitHub issue #158's `supportsCodeLookup()` is genuinely
 * `false`, since TMDB has no barcode/EAN/UPC lookup capability at all (see
 * TmdbProvider's own docblock for how that was confirmed). Covers the
 * search()-only integration: request shape, response-field mapping onto
 * MediaDvdBluray's columns, genre-id-to-name translation via the cached
 * genre list, and the always-empty lookupByCode().
 */
class TmdbProviderTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://api.themoviedb.org/3';

    /** Deliberately `read_access_token`, not `api_key` — see TmdbProvider::configFields()'s own comment for why those are two genuinely different TMDB credentials. */
    private function withReadAccessToken(string $token = 'test-read-access-token'): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.tmdb',
            'name' => 'TMDB',
            'media_type' => 'dvd_bluray',
            'enabled' => true,
            'config' => ['read_access_token' => $token],
        ]);
    }

    /** Shaped after TMDB's documented /search/movie response fields. */
    private function sampleSearchResult(): array
    {
        return [
            'id' => 603,
            'title' => 'The Matrix',
            'original_title' => 'The Matrix',
            'overview' => 'Set in the 22nd century, The Matrix tells the story of a computer hacker...',
            'release_date' => '1999-03-30',
            'genre_ids' => [28, 878],
            'poster_path' => '/f89U3ADr1oiB1s9GkdPOEpXUk5H.jpg',
            'vote_average' => 8.2,
        ];
    }

    private function fakeGenreList(): void
    {
        Http::fake([
            self::BASE_URL.'/genre/movie/list*' => Http::response([
                'genres' => [
                    ['id' => 28, 'name' => 'Action'],
                    ['id' => 878, 'name' => 'Science Fiction'],
                ],
            ], 200),
        ]);
    }

    public function test_key_and_media_type(): void
    {
        $provider = app(TmdbProvider::class);

        $this->assertSame('dvd_bluray.tmdb', $provider->key());
        $this->assertSame('TMDB', $provider->name());
        $this->assertSame('dvd_bluray', $provider->mediaType());
    }

    /** GitHub issue #158: the whole point of this provider — no barcode lookup capability at all. */
    public function test_does_not_support_code_lookup(): void
    {
        $this->assertFalse(app(TmdbProvider::class)->supportsCodeLookup());
    }

    /** GitHub issue #157: confirmed against the live API reference — no code-based lookup is even attempted. */
    public function test_lookup_by_code_always_returns_no_candidates(): void
    {
        $this->withReadAccessToken();
        Http::fake(); // Nothing faked at all — a real request here would fail the test.

        $candidates = app(TmdbProvider::class)->lookupByCode('4006680095609');

        $this->assertSame([], $candidates);
    }

    public function test_search_calls_the_search_endpoint_with_a_bearer_token(): void
    {
        $this->withReadAccessToken('secret-token-123');
        $this->fakeGenreList();
        Http::fake([
            self::BASE_URL.'/search/movie*' => Http::response(['results' => [$this->sampleSearchResult()]], 200),
            self::BASE_URL.'/genre/movie/list*' => Http::response(['genres' => [
                ['id' => 28, 'name' => 'Action'], ['id' => 878, 'name' => 'Science Fiction'],
            ]], 200),
        ]);

        $candidates = app(TmdbProvider::class)->search('The Matrix');

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), self::BASE_URL.'/search/movie')
                && $request['query'] === 'The Matrix'
                && $request->hasHeader('Authorization', 'Bearer secret-token-123');
        });
        $this->assertCount(1, $candidates);
    }

    public function test_search_maps_the_response_onto_media_dvd_bluray_columns(): void
    {
        $this->withReadAccessToken();
        Http::fake([
            self::BASE_URL.'/search/movie*' => Http::response(['results' => [$this->sampleSearchResult()]], 200),
            self::BASE_URL.'/genre/movie/list*' => Http::response(['genres' => [
                ['id' => 28, 'name' => 'Action'], ['id' => 878, 'name' => 'Science Fiction'],
            ]], 200),
        ]);

        $candidate = app(TmdbProvider::class)->search('The Matrix')[0];

        $this->assertSame('dvd_bluray.tmdb', $candidate->providerKey);
        $this->assertSame('603', $candidate->sourceId);
        $this->assertSame([
            'title' => 'The Matrix',
            'description' => 'Set in the 22nd century, The Matrix tells the story of a computer hacker...',
            'genre' => 'Action, Science Fiction',
            'release_date' => '1999-03-30',
            'production_year' => 1999,
        ], $candidate->attributes);
        $this->assertSame(['https://image.tmdb.org/t/p/w500/f89U3ADr1oiB1s9GkdPOEpXUk5H.jpg'], $candidate->coverUrls);
        // Never mapped by this provider — see its own docblock for why.
        $this->assertArrayNotHasKey('cast', $candidate->attributes);
        $this->assertArrayNotHasKey('director', $candidate->attributes);
        $this->assertArrayNotHasKey('runtime_minutes', $candidate->attributes);
        // No `ean` key at all, the same convention every other search()-only provider follows (see #151's own docblock note in CreateMediaItemDialog.tsx).
        $this->assertArrayNotHasKey('ean', $candidate->attributes);
    }

    /** An unreleased/unknown release_date comes back as an empty string from TMDB, not absent — must not become "1970" or an empty-but-present date. */
    public function test_an_empty_release_date_maps_to_null_release_date_and_year(): void
    {
        $this->withReadAccessToken();
        $result = $this->sampleSearchResult();
        $result['release_date'] = '';
        Http::fake([
            self::BASE_URL.'/search/movie*' => Http::response(['results' => [$result]], 200),
            self::BASE_URL.'/genre/movie/list*' => Http::response(['genres' => []], 200),
        ]);

        $candidate = app(TmdbProvider::class)->search('The Matrix')[0];

        $this->assertNull($candidate->attributes['release_date']);
        $this->assertNull($candidate->attributes['production_year']);
    }

    public function test_no_poster_path_means_no_cover_urls(): void
    {
        $this->withReadAccessToken();
        $result = $this->sampleSearchResult();
        $result['poster_path'] = null;
        Http::fake([
            self::BASE_URL.'/search/movie*' => Http::response(['results' => [$result]], 200),
            self::BASE_URL.'/genre/movie/list*' => Http::response(['genres' => []], 200),
        ]);

        $candidate = app(TmdbProvider::class)->search('The Matrix')[0];

        $this->assertSame([], $candidate->coverUrls);
    }

    /** Same "misconfigured provider silently contributes nothing to a search" convention UpcMdbProvider::search() already established. */
    public function test_search_returns_no_candidates_without_a_configured_token(): void
    {
        // No withReadAccessToken() call.
        Http::fake();

        $candidates = app(TmdbProvider::class)->search('The Matrix');

        $this->assertSame([], $candidates);
    }

    public function test_the_genre_list_is_only_fetched_once_across_multiple_searches(): void
    {
        $this->withReadAccessToken();
        Http::fake([
            self::BASE_URL.'/search/movie*' => Http::response(['results' => [$this->sampleSearchResult()]], 200),
            self::BASE_URL.'/genre/movie/list*' => Http::response(['genres' => [
                ['id' => 28, 'name' => 'Action'], ['id' => 878, 'name' => 'Science Fiction'],
            ]], 200),
        ]);

        app(TmdbProvider::class)->search('The Matrix');
        app(TmdbProvider::class)->search('The Matrix Reloaded');

        Http::assertSentCount(3); // 2 searches + 1 cached genre-list fetch, not 2.
    }
}
