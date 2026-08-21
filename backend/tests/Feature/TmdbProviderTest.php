<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\DvdBluray\TmdbProvider;
use App\Models\MetadataPlugin;
use App\Models\User;
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

    /** Shaped after TMDB's documented GET /movie/{id}?append_to_response=credits response fields, confirmed live against the official reference. */
    private function sampleMovieDetails(): array
    {
        return [
            'id' => 603,
            'runtime' => 136,
            'credits' => [
                'cast' => [
                    ['name' => 'Keanu Reeves', 'character' => 'Neo', 'order' => 0],
                    ['name' => 'Laurence Fishburne', 'character' => 'Morpheus', 'order' => 1],
                ],
                'crew' => [
                    ['name' => 'Lana Wachowski', 'job' => 'Director', 'department' => 'Directing'],
                    ['name' => 'Lilly Wachowski', 'job' => 'Director', 'department' => 'Directing'],
                    ['name' => 'Bill Pope', 'job' => 'Director of Photography', 'department' => 'Camera'],
                ],
            ],
        ];
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
        Http::fake([
            self::BASE_URL.'/search/movie*' => Http::response(['results' => [$this->sampleSearchResult()]], 200),
            self::BASE_URL.'/genre/movie/list*' => Http::response(['genres' => [
                ['id' => 28, 'name' => 'Action'], ['id' => 878, 'name' => 'Science Fiction'],
            ]], 200),
            self::BASE_URL.'/movie/603*' => Http::response($this->sampleMovieDetails(), 200),
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
            self::BASE_URL.'/movie/603*' => Http::response($this->sampleMovieDetails(), 200),
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
            // GitHub issue #165 — the first (only) result, so it's enriched.
            'runtime_minutes' => 136,
            'director' => 'Lana Wachowski, Lilly Wachowski',
            'cast' => 'Keanu Reeves, Laurence Fishburne',
        ], $candidate->attributes);
        $this->assertSame(['https://image.tmdb.org/t/p/w500/f89U3ADr1oiB1s9GkdPOEpXUk5H.jpg'], $candidate->coverUrls);
        // No `ean` key at all, the same convention every other search()-only provider follows (see #151's own docblock note in CreateMediaItemDialog.tsx).
        $this->assertArrayNotHasKey('ean', $candidate->attributes);
    }

    /** GitHub issue #165: only the first MAX_ENRICHED_RESULTS results get the extra detail request — the rest stay exactly as unenriched as before this issue. */
    public function test_only_the_first_result_is_enriched_with_credits_and_runtime(): void
    {
        $this->withReadAccessToken();
        $second = $this->sampleSearchResult();
        $second['id'] = 604;
        $second['title'] = 'The Matrix Reloaded';
        Http::fake([
            self::BASE_URL.'/search/movie*' => Http::response(['results' => [$this->sampleSearchResult(), $second]], 200),
            self::BASE_URL.'/genre/movie/list*' => Http::response(['genres' => []], 200),
            self::BASE_URL.'/movie/603*' => Http::response($this->sampleMovieDetails(), 200),
        ]);

        $candidates = app(TmdbProvider::class)->search('The Matrix');

        $this->assertSame(136, $candidates[0]->attributes['runtime_minutes']);
        $this->assertNotNull($candidates[0]->attributes['director']);
        $this->assertNull($candidates[1]->attributes['runtime_minutes']);
        $this->assertNull($candidates[1]->attributes['director']);
        $this->assertNull($candidates[1]->attributes['cast']);
        // The second result's own id (604) must never have been requested.
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), self::BASE_URL.'/movie/604'));
    }

    /** A failed enrichment request degrades to an unenriched candidate rather than losing the result (or failing the whole search) over one extra, optional request. */
    public function test_a_failed_enrichment_request_degrades_to_an_unenriched_candidate(): void
    {
        $this->withReadAccessToken();
        Http::fake([
            self::BASE_URL.'/search/movie*' => Http::response(['results' => [$this->sampleSearchResult()]], 200),
            self::BASE_URL.'/genre/movie/list*' => Http::response(['genres' => []], 200),
            self::BASE_URL.'/movie/603*' => Http::response(['status_message' => 'Service unavailable'], 503),
        ]);

        $candidates = app(TmdbProvider::class)->search('The Matrix');

        $this->assertCount(1, $candidates);
        $this->assertSame('The Matrix', $candidates[0]->attributes['title']);
        $this->assertNull($candidates[0]->attributes['runtime_minutes']);
        $this->assertNull($candidates[0]->attributes['director']);
        $this->assertNull($candidates[0]->attributes['cast']);
    }

    /** Crew members with a different job (e.g. Director of Photography) must not be mistaken for the director. */
    public function test_only_crew_with_the_director_job_are_used(): void
    {
        $this->withReadAccessToken();
        Http::fake([
            self::BASE_URL.'/search/movie*' => Http::response(['results' => [$this->sampleSearchResult()]], 200),
            self::BASE_URL.'/genre/movie/list*' => Http::response(['genres' => []], 200),
            self::BASE_URL.'/movie/603*' => Http::response($this->sampleMovieDetails(), 200),
        ]);

        $candidate = app(TmdbProvider::class)->search('The Matrix')[0];

        $this->assertStringNotContainsString('Bill Pope', $candidate->attributes['director']);
    }

    /** MAX_CAST_MEMBERS caps a potentially very long real cast list to the top-billed few, sorted by TMDB's own `order` field. */
    public function test_cast_is_capped_and_sorted_by_billing_order(): void
    {
        $this->withReadAccessToken();
        $details = $this->sampleMovieDetails();
        $details['credits']['cast'] = collect(range(0, 14))
            ->map(fn (int $i) => ['name' => "Actor {$i}", 'character' => "Role {$i}", 'order' => 14 - $i])
            ->all();
        Http::fake([
            self::BASE_URL.'/search/movie*' => Http::response(['results' => [$this->sampleSearchResult()]], 200),
            self::BASE_URL.'/genre/movie/list*' => Http::response(['genres' => []], 200),
            self::BASE_URL.'/movie/603*' => Http::response($details, 200),
        ]);

        $candidate = app(TmdbProvider::class)->search('The Matrix')[0];

        // order 14 down to 0 (reversed) — the lowest `order` values (best billing) are 0..9, i.e. "Actor 14".."Actor 5".
        $this->assertSame('Actor 14, Actor 13, Actor 12, Actor 11, Actor 10, Actor 9, Actor 8, Actor 7, Actor 6, Actor 5', $candidate->attributes['cast']);
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
            self::BASE_URL.'/movie/603*' => Http::response($this->sampleMovieDetails(), 200),
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
            self::BASE_URL.'/movie/603*' => Http::response($this->sampleMovieDetails(), 200),
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
            self::BASE_URL.'/movie/603*' => Http::response($this->sampleMovieDetails(), 200),
        ]);

        app(TmdbProvider::class)->search('The Matrix');
        app(TmdbProvider::class)->search('The Matrix Reloaded');

        // 2 searches + 1 cached genre-list fetch (not 2) + 1 enrichment call per search (both find the same id 603).
        Http::assertSentCount(5);
    }

    /** GitHub issue #170: the requesting user's own preferred_language ('de') is translated to the full locale TMDB's `language` parameter expects ('de-DE') and sent on every request this class makes. */
    public function test_search_uses_the_requesting_users_preferred_language(): void
    {
        $this->withReadAccessToken();
        $this->actingAs(User::factory()->create(['preferred_language' => 'de']));
        Http::fake([
            self::BASE_URL.'/search/movie*' => Http::response(['results' => [$this->sampleSearchResult()]], 200),
            self::BASE_URL.'/genre/movie/list*' => Http::response(['genres' => []], 200),
            self::BASE_URL.'/movie/603*' => Http::response($this->sampleMovieDetails(), 200),
        ]);

        app(TmdbProvider::class)->search('The Matrix');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), self::BASE_URL.'/search/movie') && $request['language'] === 'de-DE');
        Http::assertSent(fn ($request) => str_starts_with($request->url(), self::BASE_URL.'/genre/movie/list') && $request['language'] === 'de-DE');
        Http::assertSent(fn ($request) => str_starts_with($request->url(), self::BASE_URL.'/movie/603') && $request['language'] === 'de-DE');
    }

    /** A different preferred_language maps to its own TMDB locale, not just a hardcoded German default. */
    public function test_search_uses_english_for_an_english_preferring_user(): void
    {
        $this->withReadAccessToken();
        $this->actingAs(User::factory()->create(['preferred_language' => 'en']));
        Http::fake([
            self::BASE_URL.'/search/movie*' => Http::response(['results' => []], 200),
            self::BASE_URL.'/genre/movie/list*' => Http::response(['genres' => []], 200),
        ]);

        app(TmdbProvider::class)->search('The Matrix');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), self::BASE_URL.'/search/movie') && $request['language'] === 'en-US');
    }

    /** No authenticated user (shouldn't normally happen — every real call site runs within an authenticated request) falls back to 'de-DE', the same tolerant default PdfExportService::languageFor() uses elsewhere in this app. */
    public function test_search_falls_back_to_german_without_an_authenticated_user(): void
    {
        $this->withReadAccessToken();
        Http::fake([
            self::BASE_URL.'/search/movie*' => Http::response(['results' => []], 200),
            self::BASE_URL.'/genre/movie/list*' => Http::response(['genres' => []], 200),
        ]);

        app(TmdbProvider::class)->search('The Matrix');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), self::BASE_URL.'/search/movie') && $request['language'] === 'de-DE');
    }

    /** A preferred_language with no entry in TMDB_LANGUAGE_BY_CODE (a future language pack this map hasn't been extended for yet) falls back to TMDB's own "en-US" default rather than sending a malformed value. */
    public function test_search_falls_back_to_en_us_for_an_unmapped_language_code(): void
    {
        $this->withReadAccessToken();
        $this->actingAs(User::factory()->create(['preferred_language' => 'xx']));
        Http::fake([
            self::BASE_URL.'/search/movie*' => Http::response(['results' => []], 200),
            self::BASE_URL.'/genre/movie/list*' => Http::response(['genres' => []], 200),
        ]);

        app(TmdbProvider::class)->search('The Matrix');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), self::BASE_URL.'/search/movie') && $request['language'] === 'en-US');
    }

    /** GitHub issue #160: the precise 200-vs-401 distinction the user themselves anticipated, confirmed against TMDB's own /authentication reference. */
    public function test_test_config_returns_true_for_a_valid_token(): void
    {
        Http::fake([
            self::BASE_URL.'/authentication*' => Http::response(['success' => true, 'status_code' => 1, 'status_message' => 'Success.'], 200),
        ]);

        $valid = app(TmdbProvider::class)->testConfig(['read_access_token' => 'a-valid-token']);

        $this->assertTrue($valid);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), self::BASE_URL.'/authentication')
            && $request->hasHeader('Authorization', 'Bearer a-valid-token'));
    }

    public function test_test_config_returns_false_for_an_invalid_token(): void
    {
        Http::fake([
            self::BASE_URL.'/authentication*' => Http::response(['success' => false, 'status_code' => 7, 'status_message' => 'Invalid API key: You must be granted a valid key.'], 401),
        ]);

        $valid = app(TmdbProvider::class)->testConfig(['read_access_token' => 'a-bogus-token']);

        $this->assertFalse($valid);
    }

    public function test_test_config_returns_false_without_a_token_at_all(): void
    {
        Http::fake(); // No request should even be attempted.

        $this->assertFalse(app(TmdbProvider::class)->testConfig([]));
        $this->assertFalse(app(TmdbProvider::class)->testConfig(['read_access_token' => '']));
    }

    /** Neither a confirmed-valid nor a confirmed-invalid credential — the check itself didn't complete, so this must not be silently folded into "invalid" (GitHub issue #53's own precedent elsewhere in this app). */
    public function test_test_config_throws_on_an_unexpected_status(): void
    {
        Http::fake([
            self::BASE_URL.'/authentication*' => Http::response(['status_message' => 'Service unavailable.'], 503),
        ]);

        $this->expectException(MetadataProviderRequestException::class);
        app(TmdbProvider::class)->testConfig(['read_access_token' => 'some-token']);
    }
}
