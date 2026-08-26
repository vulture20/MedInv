<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MetadataPlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GitHub issue #192: MetadataImportService::collectCandidatesByCode()'s
 * last-resort round for a NameOnlyFallbackProvider (upcitemdb.com today) —
 * only queried when round 1's ordinary code-capable providers found
 * nothing at all, its result folded into the same candidate list (stamped
 * `stage: 'fallback'`), and able to seed GitHub issue #159's own round 2
 * for a title-only provider exactly the same way an ordinary round-1
 * candidate would.
 */
class NameOnlyFallbackProviderTest extends TestCase
{
    use RefreshDatabase;

    private const UPCMDB_BASE_URL = 'https://us-central1-upcmdb-cbae5.cloudfunctions.net/api';

    private const UPCITEMDB_BASE_URL = 'https://api.upcitemdb.com/prod/trial';

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

    private function enableUpcMdbAndUpcItemDb(): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.upcmdb', 'name' => 'UPCMDB', 'media_type' => 'dvd_bluray', 'enabled' => true,
            'config' => ['api_key' => 'test-key'],
        ]);
        MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.upcitemdb', 'name' => 'UPCitemdb', 'media_type' => 'dvd_bluray', 'enabled' => true,
        ]);
    }

    public function test_the_fallback_is_queried_and_shown_when_round_1_finds_nothing_at_all(): void
    {
        $owner = $this->actingAsOwner();
        $library = $this->library($owner->id);
        $this->enableUpcMdbAndUpcItemDb();

        Http::fake([
            self::UPCMDB_BASE_URL.'/v1/lookup/ean/*' => Http::response(['error' => 'UPC not found in database'], 404),
            self::UPCITEMDB_BASE_URL.'/lookup*' => Http::response(['code' => 'OK', 'total' => 1, 'items' => [
                ['ean' => '4006680095609', 'title' => 'The Matrix (Generic Listing)', 'images' => []],
            ]], 200),
        ]);

        $response = $this->postJson("/api/libraries/{$library->id}/capture/scan", ['ean' => '4006680095609']);

        $response->assertOk()->assertJson(['status' => 'candidates']);
        $this->assertSame('The Matrix (Generic Listing)', $response->json('merged.fields.title.value'));
        $statuses = collect($response->json('provider_statuses'))->keyBy('provider_key');
        $this->assertSame('fallback', $statuses['dvd_bluray.upcitemdb']['stage']);
        $this->assertSame('ok', $statuses['dvd_bluray.upcitemdb']['status']);
    }

    public function test_the_fallback_is_never_queried_when_an_ordinary_provider_already_found_something(): void
    {
        $owner = $this->actingAsOwner();
        $library = $this->library($owner->id);
        $this->enableUpcMdbAndUpcItemDb();

        Http::fake([
            self::UPCMDB_BASE_URL.'/v1/lookup/ean/*' => Http::response(['upc' => '4006680095609', 'title' => 'The Matrix', 'year' => 1999], 200),
        ]);

        $response = $this->postJson("/api/libraries/{$library->id}/capture/scan", ['ean' => '4006680095609']);

        $response->assertOk();
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), self::UPCITEMDB_BASE_URL));
        $statuses = collect($response->json('provider_statuses'));
        $this->assertFalse($statuses->contains('provider_key', 'dvd_bluray.upcitemdb'));
    }

    public function test_a_no_match_from_the_fallback_too_still_reports_no_match_overall(): void
    {
        $owner = $this->actingAsOwner();
        $library = $this->library($owner->id);
        $this->enableUpcMdbAndUpcItemDb();

        Http::fake([
            self::UPCMDB_BASE_URL.'/v1/lookup/ean/*' => Http::response(['error' => 'UPC not found in database'], 404),
            self::UPCITEMDB_BASE_URL.'/lookup*' => Http::response(['code' => 'INVALID_UPC', 'message' => 'Not a valid UPC code.'], 200),
        ]);

        $response = $this->postJson("/api/libraries/{$library->id}/capture/scan", ['ean' => '0000000000000']);

        $response->assertOk()->assertJson(['status' => 'no_match']);
        $statuses = collect($response->json('provider_statuses'))->keyBy('provider_key');
        $this->assertSame('fallback', $statuses['dvd_bluray.upcitemdb']['stage']);
        $this->assertSame('no_match', $statuses['dvd_bluray.upcitemdb']['status']);
    }

    /** The fallback's title is a genuine round-1 result as far as resolveCandidateTitles() is concerned — it can seed GitHub issue #159's round 2 exactly like an ordinary provider's title would. */
    public function test_a_fallback_found_title_seeds_the_title_only_round_2(): void
    {
        $owner = $this->actingAsOwner();
        $library = $this->library($owner->id);
        $this->enableUpcMdbAndUpcItemDb();
        MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.tmdb', 'name' => 'TMDB', 'media_type' => 'dvd_bluray', 'enabled' => true,
            'config' => ['read_access_token' => 'test-token'],
        ]);

        Http::fake([
            self::UPCMDB_BASE_URL.'/v1/lookup/ean/*' => Http::response(['error' => 'UPC not found in database'], 404),
            self::UPCITEMDB_BASE_URL.'/lookup*' => Http::response(['code' => 'OK', 'total' => 1, 'items' => [
                ['ean' => '4006680095609', 'title' => 'The Matrix', 'images' => []],
            ]], 200),
            self::TMDB_BASE_URL.'/search/movie*' => Http::response(['results' => [
                ['id' => 603, 'title' => 'The Matrix', 'overview' => 'A hacker discovers reality is a simulation.', 'release_date' => '1999-03-30', 'genre_ids' => []],
            ]], 200),
            self::TMDB_BASE_URL.'/genre/movie/list*' => Http::response(['genres' => []], 200),
            self::TMDB_BASE_URL.'/movie/603*' => Http::response(['id' => 603, 'runtime' => null, 'credits' => ['cast' => [], 'crew' => []]], 200),
        ]);

        $response = $this->postJson("/api/libraries/{$library->id}/capture/scan", ['ean' => '4006680095609']);

        $response->assertOk();
        Http::assertSent(fn ($request) => str_starts_with($request->url(), self::TMDB_BASE_URL.'/search/movie') && $request['query'] === 'The Matrix');
        $this->assertSame(
            'A hacker discovers reality is a simulation.',
            $response->json('merged.fields.description.value')
        );
    }
}
