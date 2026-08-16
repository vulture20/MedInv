<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaCd;
use App\Models\MetadataPlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GitHub issue #53: per-provider request status ('ok'/'no_match'/'failed')
 * surfaced alongside a metadata lookup's result, so a misconfigured/blocked
 * provider (wrong API key, rate limit, a blocked scraper like the Amazon
 * ones from #50) shows up distinctly from "this provider genuinely found
 * nothing" — previously indistinguishable from the merged result alone,
 * with the failure only ever reaching Log::warning server-side.
 */
class MetadataProviderStatusTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOwner(): User
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function enableCdProviders(): void
    {
        MetadataPlugin::query()->create(['provider_key' => 'cd.musicbrainz', 'name' => 'MusicBrainz', 'media_type' => 'cd', 'enabled' => true]);
        MetadataPlugin::query()->create(['provider_key' => 'cd.discogs', 'name' => 'Discogs', 'media_type' => 'cd', 'enabled' => true]);
    }

    public function test_a_provider_erroring_out_is_reported_as_failed_not_silently_dropped(): void
    {
        $owner = $this->actingAsOwner();
        $library = Library::query()->create(['name' => 'CDs', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        $this->enableCdProviders();

        Http::fake([
            // MusicBrainz genuinely finds nothing.
            'https://musicbrainz.org/ws/2/release*' => Http::response(['releases' => []], 200),
            // Discogs' request itself fails (e.g. rate limit / blocked).
            'https://api.discogs.com/database/search*' => Http::response(['error' => 'rate limited'], 429),
        ]);

        $response = $this->postJson("/api/libraries/{$library->id}/capture/scan", ['ean' => '724385522925']);

        $response->assertOk()->assertJson(['status' => 'no_match']);
        $statuses = collect($response->json('provider_statuses'))->keyBy('provider_key');

        $this->assertSame('no_match', $statuses['cd.musicbrainz']['status']);
        $this->assertSame(0, $statuses['cd.musicbrainz']['candidate_count']);
        $this->assertSame('failed', $statuses['cd.discogs']['status']);
        $this->assertSame(0, $statuses['cd.discogs']['candidate_count']);
    }

    public function test_a_provider_with_a_match_is_reported_ok_with_its_candidate_count(): void
    {
        $owner = $this->actingAsOwner();
        $library = Library::query()->create(['name' => 'CDs', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        MetadataPlugin::query()->create(['provider_key' => 'cd.musicbrainz', 'name' => 'MusicBrainz', 'media_type' => 'cd', 'enabled' => true]);

        Http::fake([
            'https://musicbrainz.org/ws/2/release*' => Http::response(['releases' => [
                ['id' => 'mb-1', 'title' => 'OK Computer', 'artist-credit' => [['name' => 'Radiohead']], 'date' => '1997-07-01'],
            ]], 200),
        ]);

        $response = $this->postJson("/api/libraries/{$library->id}/capture/scan", ['ean' => '724385522925']);

        $response->assertOk()->assertJson(['status' => 'candidates']);
        $statuses = collect($response->json('provider_statuses'))->keyBy('provider_key');

        $this->assertSame('ok', $statuses['cd.musicbrainz']['status']);
        $this->assertSame(1, $statuses['cd.musicbrainz']['candidate_count']);
    }

    /** The refresh endpoint from #56 shares the exact same lookupMerged() result shape, so it gets provider_statuses for free. */
    public function test_the_reimport_refresh_endpoint_also_reports_provider_statuses(): void
    {
        $owner = $this->actingAsOwner();
        $library = Library::query()->create(['name' => 'CDs', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        $item = MediaCd::query()->create(['library_id' => $library->id, 'title' => 'OK Computer', 'ean' => '724385522925']);
        $this->enableCdProviders();

        Http::fake([
            'https://musicbrainz.org/ws/2/release*' => Http::response(['releases' => []], 200),
            'https://api.discogs.com/database/search*' => Http::response(['error' => 'rate limited'], 429),
        ]);

        $response = $this->getJson("/api/libraries/{$library->id}/items/{$item->id}/metadata/refresh");

        $response->assertOk();
        $statuses = collect($response->json('provider_statuses'))->keyBy('provider_key');
        $this->assertSame('no_match', $statuses['cd.musicbrainz']['status']);
        $this->assertSame('failed', $statuses['cd.discogs']['status']);
    }
}
