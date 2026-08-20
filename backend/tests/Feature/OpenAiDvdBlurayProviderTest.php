<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\DvdBluray\OpenAiDvdBlurayProvider;
use App\Models\MetadataPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpenAiDvdBlurayProvider (GitHub issue #65). The trait's shared
 * request/error handling is already covered by OpenAiBookProviderTest —
 * this focuses on this provider's own DVD/Blu-ray-specific field mapping.
 */
class OpenAiDvdBlurayProviderTest extends TestCase
{
    use RefreshDatabase;

    private const API_URL = 'https://api.openai.com/v1/responses';

    private function configureApiKey(string $key = 'sk-test-key'): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.openai',
            'name' => 'ChatGPT',
            'media_type' => 'dvd_bluray',
            'enabled' => true,
            'config' => ['api_key' => $key],
        ]);
    }

    private function responsesApiResponse(array $decodedJson): array
    {
        return [
            'id' => 'resp_test', 'object' => 'response', 'status' => 'completed',
            'output' => [
                ['type' => 'message', 'id' => 'msg_test', 'status' => 'completed', 'role' => 'assistant', 'content' => [
                    ['type' => 'output_text', 'text' => json_encode($decodedJson)],
                ]],
            ],
        ];
    }

    public function test_lookup_by_code_maps_a_found_item(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->responsesApiResponse([
            'found' => true,
            'title' => 'The Matrix',
            'description' => 'A hacker discovers reality is a simulation.',
            'medium' => 'Blu-ray',
            'disc_count' => 1,
            'runtime_minutes' => 136,
            'languages' => 'English, German',
            'cast' => 'Keanu Reeves, Laurence Fishburne',
            'director' => 'Lana Wachowski, Lilly Wachowski',
            'release_date' => '1999-03-31',
            'production_year' => 1999,
        ]), 200)]);

        $candidate = app(OpenAiDvdBlurayProvider::class)->lookupByCode('7321900219658')[0];

        $this->assertSame('The Matrix', $candidate->attributes['title']);
        $this->assertSame('7321900219658', $candidate->attributes['ean']);
        $this->assertSame(136, $candidate->attributes['runtime_minutes']);
        $this->assertSame('English, German', $candidate->attributes['languages']);
        $this->assertSame(1999, $candidate->attributes['production_year']);
        $this->assertSame([], $candidate->coverUrls);
    }

    public function test_lookup_by_code_returns_no_candidates_when_not_found(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->responsesApiResponse(['found' => false]), 200)]);

        $this->assertSame([], app(OpenAiDvdBlurayProvider::class)->lookupByCode('0000000000000'));
    }

    public function test_lookup_by_code_throws_when_the_request_fails(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response(['error' => ['message' => 'server error']], 500)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(OpenAiDvdBlurayProvider::class)->lookupByCode('7321900219658');
    }

    public function test_search_maps_items_without_a_known_ean(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->responsesApiResponse([
            'items' => [
                ['found' => true, 'title' => 'The Matrix Reloaded', 'description' => null, 'medium' => null, 'disc_count' => null, 'runtime_minutes' => null, 'languages' => null, 'cast' => null, 'director' => null, 'release_date' => null, 'production_year' => null],
            ],
        ]), 200)]);

        $candidates = app(OpenAiDvdBlurayProvider::class)->search('matrix reloaded');

        $this->assertCount(1, $candidates);
        $this->assertNull($candidates[0]->attributes['ean']);
        $this->assertSame('The Matrix Reloaded', $candidates[0]->attributes['title']);
        $this->assertSame('The Matrix Reloaded', $candidates[0]->sourceId);
    }

    public function test_source_type_is_llm_and_version_carries_a_beta_suffix(): void
    {
        $provider = app(OpenAiDvdBlurayProvider::class);

        $this->assertSame('llm', $provider->sourceType());
        $this->assertStringContainsString('beta', $provider->version());
    }
}
