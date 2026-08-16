<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\DvdBluray\UpcMdbProvider;
use App\Models\MetadataPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Previously implemented (under the same class/provider-key name) against
 * the unrelated upcitemdb.com service — a mistranscription of the intended
 * source, UPCMDB (https://upcmdb.com/), corrected here. Covers the real
 * API integration: base URL, x-api-key header sourced from
 * metadata_plugins.config, response-field mapping onto MediaDvdBluray's
 * columns, a genuine 404 no-candidate result, and — since GitHub issue #53 —
 * a missing api_key or any other request failure (401/403/429/5xx) throwing
 * MetadataProviderRequestException instead of silently returning [], so
 * that's reported as 'failed' rather than 'no_match'.
 */
class UpcMdbProviderTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://us-central1-upcmdb-cbae5.cloudfunctions.net/api';

    private function withApiKey(string $key = 'test-api-key'): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.upcmdb',
            'name' => 'UPCMDB',
            'media_type' => 'dvd_bluray',
            'enabled' => true,
            'config' => ['api_key' => $key],
        ]);
    }

    /** The documented UPCMDB Response Object shape, from the API reference. */
    private function sampleResponse(): array
    {
        return [
            'upc' => '853901163114',
            'title' => 'Full Metal Jacket',
            'year' => 1987,
            'format' => 'DVD (Repackaged)',
            'special_features' => 'DVD (Repackaged)',
            'publisher' => 'Warner Home Video',
            'imdbId' => 'tt0093058',
            'type' => 'movie',
            'plot' => 'A pragmatic U.S. Marine observes the dehumanizing effects the Vietnam War has...',
            'runtime' => '116 min',
            'genre' => 'Drama, War',
            'director' => 'Stanley Kubrick',
            'actors' => 'Matthew Modine, R. Lee Ermey, Vincent D\'Onofrio',
            'imdbRating' => 8.2,
            'rated' => 'R',
            'mediaType' => 'movie',
        ];
    }

    public function test_key_and_name_reflect_the_corrected_provider(): void
    {
        $provider = app(UpcMdbProvider::class);

        $this->assertSame('dvd_bluray.upcmdb', $provider->key());
        $this->assertSame('UPCMDB', $provider->name());
        $this->assertSame('dvd_bluray', $provider->mediaType());
    }

    public function test_lookup_by_code_calls_the_ean_endpoint_with_the_configured_api_key(): void
    {
        $this->withApiKey('secret-key-123');
        Http::fake([self::BASE_URL.'/v1/lookup/ean/*' => Http::response($this->sampleResponse(), 200)]);

        $candidates = app(UpcMdbProvider::class)->lookupByCode('853901163114');

        Http::assertSent(function ($request) {
            return $request->url() === self::BASE_URL.'/v1/lookup/ean/853901163114'
                && $request->hasHeader('x-api-key', 'secret-key-123');
        });
        $this->assertCount(1, $candidates);
    }

    public function test_lookup_by_code_maps_the_response_onto_media_dvd_bluray_columns(): void
    {
        $this->withApiKey();
        Http::fake([self::BASE_URL.'/v1/lookup/ean/*' => Http::response($this->sampleResponse(), 200)]);

        $candidate = app(UpcMdbProvider::class)->lookupByCode('853901163114')[0];

        $this->assertSame('dvd_bluray.upcmdb', $candidate->providerKey);
        $this->assertSame('853901163114', $candidate->sourceId);
        $this->assertSame([
            'title' => 'Full Metal Jacket',
            'description' => 'A pragmatic U.S. Marine observes the dehumanizing effects the Vietnam War has...',
            'medium' => 'DVD (Repackaged)',
            'director' => 'Stanley Kubrick',
            'cast' => 'Matthew Modine, R. Lee Ermey, Vincent D\'Onofrio',
            'production_year' => 1987,
            'runtime_minutes' => 116,
            'ean' => '853901163114',
        ], $candidate->attributes);
        // UPCMDB's documented response has no cover-image field.
        $this->assertSame([], $candidate->coverUrls);
    }

    public function test_lookup_by_code_throws_without_a_configured_api_key(): void
    {
        // No withApiKey() call — no metadata_plugins row at all. GitHub
        // issue #53: a missing required config field is a misconfiguration,
        // not a genuine no-match.
        Http::fake();

        $this->expectException(MetadataProviderRequestException::class);
        app(UpcMdbProvider::class)->lookupByCode('853901163114');
    }

    public function test_lookup_by_code_returns_no_candidates_when_not_found(): void
    {
        $this->withApiKey();
        Http::fake([self::BASE_URL.'/v1/lookup/ean/*' => Http::response(['error' => 'UPC not found in database'], 404)]);

        $candidates = app(UpcMdbProvider::class)->lookupByCode('0000000000000');

        $this->assertSame([], $candidates);
    }

    /** GitHub issue #53: unlike a genuine 404, any other failure (wrong/expired key, rate limit, ...) means the request itself didn't succeed. */
    public function test_lookup_by_code_throws_on_a_non_404_failure(): void
    {
        $this->withApiKey();
        Http::fake([self::BASE_URL.'/v1/lookup/ean/*' => Http::response(['error' => 'Invalid API key'], 401)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(UpcMdbProvider::class)->lookupByCode('853901163114');
    }

    public function test_search_calls_the_search_endpoint_with_the_title_query(): void
    {
        $this->withApiKey('secret-key-123');
        Http::fake([self::BASE_URL.'/v1/search*' => Http::response([$this->sampleResponse()], 200)]);

        $candidates = app(UpcMdbProvider::class)->search('Full Metal Jacket');

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), self::BASE_URL.'/v1/search')
                && $request['title'] === 'Full Metal Jacket'
                && $request->hasHeader('x-api-key', 'secret-key-123');
        });
        $this->assertCount(1, $candidates);
        $this->assertSame('Full Metal Jacket', $candidates[0]->attributes['title']);
        // No EAN was supplied up front (unlike lookupByCode) — falls back to the item's own upc.
        $this->assertSame('853901163114', $candidates[0]->attributes['ean']);
    }

    public function test_a_runtime_without_a_number_maps_to_a_null_runtime(): void
    {
        $this->withApiKey();
        $response = $this->sampleResponse();
        $response['runtime'] = null;
        Http::fake([self::BASE_URL.'/v1/lookup/ean/*' => Http::response($response, 200)]);

        $candidate = app(UpcMdbProvider::class)->lookupByCode('853901163114')[0];

        $this->assertNull($candidate->attributes['runtime_minutes']);
    }
}
