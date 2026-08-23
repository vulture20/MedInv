<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Cd\MistralCdProvider;
use App\Models\MetadataPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MistralCdProvider (GitHub issue #68). The trait's shared request/error
 * handling is already covered by MistralBookProviderTest — this focuses on
 * this provider's own CD-specific field mapping, in particular `tracks`
 * (never `runtime_seconds` directly, same as ClaudeCdProvider/
 * MusicBrainzProvider — see MistralCdProvider's docblock).
 */
class MistralCdProviderTest extends TestCase
{
    use RefreshDatabase;

    private const API_URL = 'https://api.mistral.ai/v1/conversations';

    private function configureApiKey(string $key = 'test-key'): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'cd.mistral',
            'name' => 'Mistral',
            'media_type' => 'cd',
            'enabled' => true,
            'config' => ['api_key' => $key],
        ]);
    }

    private function conversationsApiResponse(array $decodedJson): array
    {
        return [
            'conversation_id' => 'conv_test',
            'outputs' => [
                ['type' => 'message.output', 'content' => json_encode($decodedJson)],
            ],
        ];
    }

    public function test_lookup_by_code_maps_a_found_item_including_tracks(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->conversationsApiResponse([
            'found' => true,
            'title' => 'The Dark Side of the Moon',
            'artist' => 'Pink Floyd',
            'description' => null,
            'medium' => 'CD',
            'disc_count' => 1,
            'release_date' => '1973-03-01',
            'tracks' => [
                ['position' => '1', 'title' => 'Speak to Me', 'duration_seconds' => 90],
                ['position' => '2', 'title' => 'Breathe', 'duration_seconds' => 163],
            ],
        ]), 200)]);

        $candidate = app(MistralCdProvider::class)->lookupByCode('5099902988228')[0];

        $this->assertSame('The Dark Side of the Moon', $candidate->attributes['title']);
        $this->assertSame('Pink Floyd', $candidate->attributes['artist']);
        $this->assertSame('5099902988228', $candidate->attributes['ean']);
        $this->assertArrayNotHasKey('runtime_seconds', $candidate->attributes);
        $this->assertCount(2, $candidate->attributes['tracks']);
        $this->assertSame('Breathe', $candidate->attributes['tracks'][1]['title']);
        $this->assertSame([], $candidate->coverUrls);
    }

    public function test_lookup_by_code_returns_no_candidates_when_not_found(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->conversationsApiResponse(['found' => false]), 200)]);

        $this->assertSame([], app(MistralCdProvider::class)->lookupByCode('0000000000000'));
    }

    public function test_lookup_by_code_throws_when_the_request_fails(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response(['message' => 'server error'], 500)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(MistralCdProvider::class)->lookupByCode('5099902988228');
    }

    public function test_search_maps_items_without_a_known_ean(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->conversationsApiResponse([
            'items' => [
                ['found' => true, 'title' => 'Wish You Were Here', 'artist' => 'Pink Floyd', 'description' => null, 'medium' => null, 'disc_count' => null, 'release_date' => null, 'tracks' => null],
            ],
        ]), 200)]);

        $candidates = app(MistralCdProvider::class)->search('pink floyd wish you were here');

        $this->assertCount(1, $candidates);
        $this->assertNull($candidates[0]->attributes['ean']);
        $this->assertSame('Wish You Were Here', $candidates[0]->attributes['title']);
        $this->assertSame('Wish You Were Here', $candidates[0]->sourceId);
    }

    public function test_source_type_is_llm(): void
    {
        $this->assertSame('llm', app(MistralCdProvider::class)->sourceType());
    }
}
