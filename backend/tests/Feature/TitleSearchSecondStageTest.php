<?php

namespace Tests\Feature;

use App\Domain\Capture\BulkImportService;
use App\Models\Library;
use App\Models\MediaDvdBluray;
use App\Models\MetadataPlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GitHub issue #159: a provider that can never contribute through
 * lookupByCode() at all (`supportsCodeLookup() === false`, GitHub issue
 * #158 — TMDB is the only one so far) gets a second, title-based round
 * once the first (EAN) round's candidates agree on a title, instead of
 * only ever being reachable through the manual free-text search (#151).
 * See MetadataImportService::collectCandidatesByCode()'s own docblock for
 * the two deliberate scoping decisions this covers: round 2 only runs
 * when round 1's title is unambiguous, and only for providers that
 * structurally have no other way to ever contribute.
 */
class TitleSearchSecondStageTest extends TestCase
{
    use RefreshDatabase;

    private const UPCMDB_BASE_URL = 'https://us-central1-upcmdb-cbae5.cloudfunctions.net/api';

    private const TMDB_BASE_URL = 'https://api.themoviedb.org/3';

    private function actingAsOwner(): User
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function library(int $ownerId): Library
    {
        return Library::query()->create(['name' => 'Filme', 'media_type' => 'dvd_bluray', 'owner_id' => $ownerId]);
    }

    private function enableUpcMdbAndTmdb(): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.upcmdb', 'name' => 'UPCMDB', 'media_type' => 'dvd_bluray', 'enabled' => true,
            'config' => ['api_key' => 'test-key'],
        ]);
        MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.tmdb', 'name' => 'TMDB', 'media_type' => 'dvd_bluray', 'enabled' => true,
            'config' => ['read_access_token' => 'test-token'],
        ]);
    }

    private function fakeTmdbGenreList(): void
    {
        Http::fake([self::TMDB_BASE_URL.'/genre/movie/list*' => Http::response(['genres' => []], 200)]);
    }

    public function test_a_title_only_provider_is_queried_by_the_title_round_1_agreed_on(): void
    {
        $owner = $this->actingAsOwner();
        $library = $this->library($owner->id);
        $this->enableUpcMdbAndTmdb();

        Http::fake([
            self::UPCMDB_BASE_URL.'/v1/lookup/ean/*' => Http::response(['upc' => '4006680095609', 'title' => 'The Matrix', 'year' => 1999], 200),
            self::TMDB_BASE_URL.'/search/movie*' => Http::response(['results' => [
                ['id' => 603, 'title' => 'The Matrix', 'overview' => 'A hacker discovers reality is a simulation.', 'release_date' => '1999-03-30', 'genre_ids' => []],
            ]], 200),
            self::TMDB_BASE_URL.'/genre/movie/list*' => Http::response(['genres' => []], 200),
        ]);

        $response = $this->postJson("/api/libraries/{$library->id}/capture/scan", ['ean' => '4006680095609']);

        $response->assertOk()->assertJson(['status' => 'candidates']);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), self::TMDB_BASE_URL.'/search/movie') && $request['query'] === 'The Matrix');
        $this->assertSame(
            'A hacker discovers reality is a simulation.',
            $response->json('merged.fields.description.value')
        );
    }

    /** The whole point of #158's supportsCodeLookup() flag: TMDB's lookupByCode() is never even called, since it's structurally guaranteed to return []. */
    public function test_the_title_only_providers_lookupbycode_is_never_called(): void
    {
        $owner = $this->actingAsOwner();
        $library = $this->library($owner->id);
        $this->enableUpcMdbAndTmdb();

        Http::fake([
            self::UPCMDB_BASE_URL.'/v1/lookup/ean/*' => Http::response(['upc' => '4006680095609', 'title' => 'The Matrix', 'year' => 1999], 200),
            self::TMDB_BASE_URL.'/search/movie*' => Http::response(['results' => []], 200),
        ]);
        $this->fakeTmdbGenreList();

        $this->postJson("/api/libraries/{$library->id}/capture/scan", ['ean' => '4006680095609']);

        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), self::TMDB_BASE_URL) && ! str_contains($request->url(), '/search/movie') && ! str_contains($request->url(), '/genre/movie/list'));
    }

    /**
     * GitHub issue #159's own follow-up (explicit user request): a
     * disagreement between round-1 providers no longer skips round 2
     * outright — TMDB is tried against *each* disagreeing title instead of
     * giving up, since the correct one is usually still among them.
     */
    public function test_round_2_tries_every_disagreeing_title_instead_of_skipping(): void
    {
        $owner = $this->actingAsOwner();
        $library = $this->library($owner->id);
        $this->enableUpcMdbAndTmdb();
        // A second EAN-capable provider so there's something to disagree with — JpcDvdBlurayProvider,
        // reusing JpcDvdBlurayProviderTest's own minimal search+product-page fixture shape.
        MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.jpc', 'name' => 'JPC', 'media_type' => 'dvd_bluray', 'enabled' => true,
        ]);

        Http::fake([
            self::UPCMDB_BASE_URL.'/v1/lookup/ean/*' => Http::response(['upc' => '4006680095609', 'title' => 'The Matrix', 'year' => 1999], 200),
            'https://www.jpc.de/jpcng/home/search*' => Http::response(
                '<html><body><a href="/jpcng/movie/detail/-/art/a-different-film/hnum/1">A Different Film</a></body></html>',
                200
            ),
            'https://www.jpc.de/jpcng/*/detail/-/art/*' => Http::response(
                '<html><head><title>A Different Film (DVD) – jpc.de</title></head><body></body></html>',
                200
            ),
            self::TMDB_BASE_URL.'/search/movie*' => Http::sequence()
                ->push(['results' => [['id' => 603, 'title' => 'The Matrix', 'overview' => 'Found via the first title.', 'release_date' => '1999-03-30', 'genre_ids' => []]]])
                ->push(['results' => []]),
        ]);
        $this->fakeTmdbGenreList();

        $response = $this->postJson("/api/libraries/{$library->id}/capture/scan", ['ean' => '4006680095609']);

        $response->assertOk();
        Http::assertSent(fn ($request) => str_starts_with($request->url(), self::TMDB_BASE_URL.'/search/movie') && $request['query'] === 'The Matrix');
        Http::assertSent(fn ($request) => str_starts_with($request->url(), self::TMDB_BASE_URL.'/search/movie') && $request['query'] === 'A Different Film');
        $statuses = collect($response->json('provider_statuses'))->keyBy('provider_key');
        $this->assertSame('ok', $statuses['dvd_bluray.tmdb']['status']);
        $this->assertSame(1, $statuses['dvd_bluray.tmdb']['candidate_count']);
    }

    /**
     * MAX_TITLE_CANDIDATES caps round 2's attempts — four EAN-capable
     * providers here (UPCMDB, Claude, OpenAI, Gemini) each disagree on a
     * genuinely distinct title, so TMDB gets tried for at most 3 of the 4,
     * not one call per disagreeing title. Which 3 "win" is decided by
     * MetadataMerger's own per-option provider-count ranking (already
     * covered by MetadataMergerTest.php) — this test focuses on the cap
     * actually being applied, not re-verifying that ranking itself.
     */
    public function test_round_2_tries_at_most_max_title_candidates_titles(): void
    {
        $owner = $this->actingAsOwner();
        $library = $this->library($owner->id);
        $this->enableUpcMdbAndTmdb();
        MetadataPlugin::query()->create(['provider_key' => 'dvd_bluray.claude', 'name' => 'Claude', 'media_type' => 'dvd_bluray', 'enabled' => true, 'config' => ['api_key' => 'sk-ant-test']]);
        MetadataPlugin::query()->create(['provider_key' => 'dvd_bluray.openai', 'name' => 'OpenAI', 'media_type' => 'dvd_bluray', 'enabled' => true, 'config' => ['api_key' => 'sk-test']]);
        MetadataPlugin::query()->create(['provider_key' => 'dvd_bluray.gemini', 'name' => 'Gemini', 'media_type' => 'dvd_bluray', 'enabled' => true, 'config' => ['api_key' => 'gemini-test']]);

        Http::fake([
            self::UPCMDB_BASE_URL.'/v1/lookup/ean/*' => Http::response(['upc' => '4006680095609', 'title' => 'Title A', 'year' => 1999], 200),
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => json_encode(['found' => true, 'title' => 'Title B'])]],
            ], 200),
            'https://api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_test', 'object' => 'response', 'status' => 'completed',
                'output' => [['type' => 'message', 'id' => 'msg_test', 'status' => 'completed', 'role' => 'assistant', 'content' => [
                    ['type' => 'output_text', 'text' => json_encode(['found' => true, 'title' => 'Title C'])],
                ]]],
            ], 200),
            'https://generativelanguage.googleapis.com/v1beta/models/*:generateContent' => Http::response([
                'candidates' => [['content' => ['role' => 'model', 'parts' => [
                    ['text' => json_encode(['found' => true, 'title' => 'Title D'])],
                ]], 'finishReason' => 'STOP', 'index' => 0]],
            ], 200),
            self::TMDB_BASE_URL.'/search/movie*' => Http::response(['results' => []], 200),
        ]);
        $this->fakeTmdbGenreList();

        $this->postJson("/api/libraries/{$library->id}/capture/scan", ['ean' => '4006680095609']);

        $tmdbSearchCalls = collect(Http::recorded(fn ($request) => str_starts_with($request->url(), self::TMDB_BASE_URL.'/search/movie')));
        $this->assertCount(3, $tmdbSearchCalls);
    }

    public function test_round_2_is_skipped_when_round_1_finds_nothing_at_all(): void
    {
        $owner = $this->actingAsOwner();
        $library = $this->library($owner->id);
        $this->enableUpcMdbAndTmdb();

        Http::fake([
            self::UPCMDB_BASE_URL.'/v1/lookup/ean/*' => Http::response(['error' => 'UPC not found in database'], 404),
        ]);

        $response = $this->postJson("/api/libraries/{$library->id}/capture/scan", ['ean' => '0000000000000']);

        $response->assertOk()->assertJson(['status' => 'no_match']);
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), self::TMDB_BASE_URL));
        $statuses = collect($response->json('provider_statuses'))->keyBy('provider_key');
        $this->assertSame('skipped', $statuses['dvd_bluray.tmdb']['status']);
    }

    public function test_round_1_statuses_are_stamped_with_the_code_stage(): void
    {
        $owner = $this->actingAsOwner();
        $library = $this->library($owner->id);
        $this->enableUpcMdbAndTmdb();

        Http::fake([
            self::UPCMDB_BASE_URL.'/v1/lookup/ean/*' => Http::response(['upc' => '4006680095609', 'title' => 'The Matrix', 'year' => 1999], 200),
            self::TMDB_BASE_URL.'/search/movie*' => Http::response(['results' => []], 200),
        ]);
        $this->fakeTmdbGenreList();

        $response = $this->postJson("/api/libraries/{$library->id}/capture/scan", ['ean' => '4006680095609']);

        $statuses = collect($response->json('provider_statuses'))->keyBy('provider_key');
        $this->assertSame('code', $statuses['dvd_bluray.upcmdb']['stage']);
        $this->assertSame('title', $statuses['dvd_bluray.tmdb']['stage']);
        $this->assertSame('no_match', $statuses['dvd_bluray.tmdb']['status']);
    }

    /**
     * A round-2 provider's failing request is reported the same way its
     * search() behaves everywhere else in this app — TmdbProvider::search()
     * (like every other provider's search(), e.g. UpcMdbProvider's own
     * documented "return [], don't throw" behavior) never distinguishes a
     * failed request from a genuine no-match; only lookupByCode() does
     * (#53's own explicit scoping). Round 2 reuses search() as-is rather
     * than introducing a new, inconsistent "search that throws" variant
     * just for this — so a request failure here degrades to `no_match`,
     * the same as it always has for a free-text search.
     */
    public function test_a_round_2_providers_failing_request_degrades_to_no_match_not_failed(): void
    {
        $owner = $this->actingAsOwner();
        $library = $this->library($owner->id);
        $this->enableUpcMdbAndTmdb();

        Http::fake([
            self::UPCMDB_BASE_URL.'/v1/lookup/ean/*' => Http::response(['upc' => '4006680095609', 'title' => 'The Matrix', 'year' => 1999], 200),
            self::TMDB_BASE_URL.'/search/movie*' => Http::response(['status_message' => 'Service unavailable'], 503),
        ]);

        $response = $this->postJson("/api/libraries/{$library->id}/capture/scan", ['ean' => '4006680095609']);

        $statuses = collect($response->json('provider_statuses'))->keyBy('provider_key');
        $this->assertSame('no_match', $statuses['dvd_bluray.tmdb']['status']);
        $this->assertSame('title', $statuses['dvd_bluray.tmdb']['stage']);
    }

    /** BulkImportService::resolveOne() shares the exact same collectCandidatesByCode() path, so it gets round 2 for free. */
    public function test_bulk_import_also_gets_the_second_round(): void
    {
        $owner = $this->actingAsOwner();
        $library = $this->library($owner->id);
        $this->enableUpcMdbAndTmdb();

        Http::fake([
            self::UPCMDB_BASE_URL.'/v1/lookup/ean/*' => Http::response(['upc' => '4006680095609', 'title' => 'The Matrix', 'year' => 1999], 200),
            self::TMDB_BASE_URL.'/search/movie*' => Http::response(['results' => [
                ['id' => 603, 'title' => 'The Matrix', 'overview' => 'A hacker discovers reality is a simulation.', 'release_date' => '1999-03-30', 'genre_ids' => []],
            ]], 200),
        ]);
        $this->fakeTmdbGenreList();

        $result = app(BulkImportService::class)->resolveOne($library, '4006680095609');

        $this->assertSame('A hacker discovers reality is a simulation.', $result['merged']['fields']['description']['value']);
    }

    /** MediaItemDetailDialog's "refresh metadata" (#56) shares the same lookupMerged() path too. */
    public function test_the_refresh_endpoint_also_gets_the_second_round(): void
    {
        $owner = $this->actingAsOwner();
        $library = $this->library($owner->id);
        $item = MediaDvdBluray::query()->create(['library_id' => $library->id, 'title' => 'The Matrix', 'ean' => '4006680095609']);
        $this->enableUpcMdbAndTmdb();

        Http::fake([
            self::UPCMDB_BASE_URL.'/v1/lookup/ean/*' => Http::response(['upc' => '4006680095609', 'title' => 'The Matrix', 'year' => 1999], 200),
            self::TMDB_BASE_URL.'/search/movie*' => Http::response(['results' => [
                ['id' => 603, 'title' => 'The Matrix', 'overview' => 'A hacker discovers reality is a simulation.', 'release_date' => '1999-03-30', 'genre_ids' => []],
            ]], 200),
        ]);
        $this->fakeTmdbGenreList();

        $response = $this->getJson("/api/libraries/{$library->id}/items/{$item->id}/metadata/refresh");

        $response->assertOk();
        $statuses = collect($response->json('provider_statuses'))->keyBy('provider_key');
        $this->assertSame('title', $statuses['dvd_bluray.tmdb']['stage']);
    }
}
