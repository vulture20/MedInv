<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Cd\GeminiCdProvider;
use App\Models\MetadataPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GeminiCdProvider (GitHub issue #66). The trait's shared request/error
 * handling is already covered by GeminiBookProviderTest — this focuses on
 * this provider's own CD-specific field mapping, in particular `tracks`
 * (never `runtime_seconds` directly, same as ClaudeCdProvider/
 * OpenAiCdProvider/MusicBrainzProvider — see GeminiCdProvider's docblock).
 */
class GeminiCdProviderTest extends TestCase
{
    use RefreshDatabase;

    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.7-flash:generateContent';

    private function configureApiKey(string $key = 'AIza-test-key'): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'cd.gemini',
            'name' => 'Gemini',
            'media_type' => 'cd',
            'enabled' => true,
            'config' => ['api_key' => $key],
        ]);
    }

    private function generateContentResponse(array $decodedJson): array
    {
        return [
            'candidates' => [
                ['content' => ['role' => 'model', 'parts' => [['text' => json_encode($decodedJson)]]], 'finishReason' => 'STOP', 'index' => 0],
            ],
        ];
    }

    public function test_lookup_by_code_maps_a_found_item_including_tracks(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->generateContentResponse([
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

        $candidate = app(GeminiCdProvider::class)->lookupByCode('5099902988228')[0];

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
        Http::fake([self::API_URL => Http::response($this->generateContentResponse(['found' => false]), 200)]);

        $this->assertSame([], app(GeminiCdProvider::class)->lookupByCode('0000000000000'));
    }

    public function test_lookup_by_code_throws_when_the_request_fails(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response(['error' => ['message' => 'server error']], 500)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(GeminiCdProvider::class)->lookupByCode('5099902988228');
    }

    public function test_search_maps_items_without_a_known_ean(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->generateContentResponse([
            'items' => [
                ['found' => true, 'title' => 'Wish You Were Here', 'artist' => 'Pink Floyd', 'description' => null, 'medium' => null, 'disc_count' => null, 'release_date' => null, 'tracks' => null],
            ],
        ]), 200)]);

        $candidates = app(GeminiCdProvider::class)->search('pink floyd wish you were here');

        $this->assertCount(1, $candidates);
        $this->assertNull($candidates[0]->attributes['ean']);
        $this->assertSame('Wish You Were Here', $candidates[0]->attributes['title']);
        $this->assertSame('Wish You Were Here', $candidates[0]->sourceId);
    }

    public function test_source_type_is_llm(): void
    {
        $this->assertSame('llm', app(GeminiCdProvider::class)->sourceType());
    }
}
