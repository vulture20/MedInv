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
 * End-to-end coverage for the capture flow's field-by-field metadata merge
 * (see MetadataMerger's docblock) through the real POST
 * /libraries/{library}/capture/scan endpoint — MetadataMergerTest already
 * covers the merge algorithm itself in isolation; this proves it's actually
 * wired up through BulkImportService/MetadataImportService with two real,
 * differently-shaped providers (MusicBrainz + Discogs, both 'cd') rather
 * than hand-built MetadataCandidate objects.
 */
class BulkImportServiceTest extends TestCase
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

    private function fakeMusicBrainz(array $releases): void
    {
        Http::fake(['https://musicbrainz.org/ws2/release*' => Http::response(['releases' => $releases], 200)]);
    }

    /** Merges Http::fake() setups for both providers — Http::fake() calls replace each other, so both must be registered in one call. */
    private function fakeBothProviders(array $musicBrainzReleases, array $discogsSearchResult, array $discogsRelease): void
    {
        Http::fake([
            'https://musicbrainz.org/ws2/release*' => Http::response(['releases' => $musicBrainzReleases], 200),
            'https://api.discogs.com/database/search*' => Http::response(['results' => [$discogsSearchResult]], 200),
            'https://api.discogs.com/releases/*' => Http::response($discogsRelease, 200),
        ]);
    }

    public function test_a_field_the_two_providers_disagree_on_is_offered_as_separate_options(): void
    {
        $owner = $this->actingAsOwner();
        $library = Library::query()->create(['name' => 'CDs', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        $this->enableCdProviders();

        $this->fakeBothProviders(
            musicBrainzReleases: [
                ['id' => 'mb-1', 'title' => 'OK Computer', 'artist-credit' => [['name' => 'Radiohead']], 'date' => '1997-07-01'],
            ],
            discogsSearchResult: ['id' => 999, 'title' => 'Radiohead - OK Computer'],
            discogsRelease: [
                'id' => 999,
                'title' => 'OK Computer (Collector\'s Edition)',
                'artists_sort' => 'Radiohead',
                'images' => [['type' => 'primary', 'uri' => 'https://i.discogs.com/cover.jpeg']],
            ],
        );

        $response = $this->postJson("/api/libraries/{$library->id}/capture/scan", ['ean' => '724385522925']);

        $response->assertOk()->assertJson(['status' => 'candidates']);
        $merged = $response->json('merged');

        $this->assertFalse($merged['fields']['title']['agreed']);
        $this->assertEqualsCanonicalizing(
            ['OK Computer', 'OK Computer (Collector\'s Edition)'],
            array_column($merged['fields']['title']['options'], 'value'),
        );

        // Both providers agree on the artist, so it's merged automatically.
        $this->assertTrue($merged['fields']['artist']['agreed']);
        $this->assertSame('Radiohead', $merged['fields']['artist']['value']);

        // Only Discogs has a cover in this fixture.
        $this->assertSame([['url' => 'https://i.discogs.com/cover.jpeg', 'provider_key' => 'cd.discogs']], $merged['covers']);
    }

    public function test_a_field_only_one_provider_reports_is_merged_as_agreed(): void
    {
        $owner = $this->actingAsOwner();
        $library = Library::query()->create(['name' => 'CDs', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        MetadataPlugin::query()->create(['provider_key' => 'cd.musicbrainz', 'name' => 'MusicBrainz', 'media_type' => 'cd', 'enabled' => true]);

        $this->fakeMusicBrainz([
            ['id' => 'mb-1', 'title' => 'OK Computer', 'artist-credit' => [['name' => 'Radiohead']], 'date' => '1997-07-01'],
        ]);

        $response = $this->postJson("/api/libraries/{$library->id}/capture/scan", ['ean' => '724385522925']);

        $merged = $response->json('merged');
        $this->assertTrue($merged['fields']['title']['agreed']);
        $this->assertSame('OK Computer', $merged['fields']['title']['value']);
        $this->assertSame([], $merged['covers']);
    }

    public function test_no_enabled_provider_finding_anything_reports_no_match(): void
    {
        $owner = $this->actingAsOwner();
        $library = Library::query()->create(['name' => 'CDs', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        $this->enableCdProviders();
        Http::fake([
            'https://musicbrainz.org/ws2/release*' => Http::response(['releases' => []], 200),
            'https://api.discogs.com/database/search*' => Http::response(['results' => []], 200),
        ]);

        $response = $this->postJson("/api/libraries/{$library->id}/capture/scan", ['ean' => '000000000000']);

        $response->assertOk()->assertJson(['status' => 'no_match']);
        $this->assertSame([], $response->json('merged.fields'));
        $this->assertSame([], $response->json('merged.covers'));
    }

    public function test_an_ean_already_present_in_the_library_is_reported_as_a_duplicate_without_a_provider_lookup(): void
    {
        $owner = $this->actingAsOwner();
        $library = Library::query()->create(['name' => 'CDs', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        MediaCd::query()->create(['library_id' => $library->id, 'title' => 'Existing', 'ean' => '724385522925']);
        $this->enableCdProviders();

        $response = $this->postJson("/api/libraries/{$library->id}/capture/scan", ['ean' => '724385522925']);

        $response->assertOk()->assertJson(['status' => 'duplicate']);
        Http::assertNothingSent();
    }
}
