<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\DvdBluray\ClaudeDvdBlurayProvider;
use App\Models\MetadataPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ClaudeDvdBlurayProvider (GitHub issue #59). The trait's shared
 * request/error handling is already covered by ClaudeBookProviderTest —
 * this focuses on this provider's own DVD/Blu-ray-specific field mapping.
 */
class ClaudeDvdBlurayProviderTest extends TestCase
{
    use RefreshDatabase;

    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private function configureApiKey(string $key = 'sk-ant-test-key'): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.claude',
            'name' => 'Claude',
            'media_type' => 'dvd_bluray',
            'enabled' => true,
            'config' => ['api_key' => $key],
        ]);
    }

    private function messagesResponse(array $decodedJson): array
    {
        return [
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode($decodedJson)]],
        ];
    }

    public function test_lookup_by_code_maps_a_found_item(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->messagesResponse([
            'found' => true,
            'title' => 'The Matrix',
            'description' => 'A hacker discovers reality is a simulation.',
            'medium' => 'Blu-ray',
            'disc_count' => 1,
            'runtime_minutes' => 136,
            'languages' => 'English, German',
            'subtitles' => 'English, German',
            'cast' => 'Keanu Reeves, Laurence Fishburne',
            'director' => 'Lana Wachowski, Lilly Wachowski',
            'genre' => 'Science Fiction',
            'release_date' => '1999-03-31',
            'production_year' => 1999,
        ]), 200)]);

        $candidate = app(ClaudeDvdBlurayProvider::class)->lookupByCode('7321900219658')[0];

        $this->assertSame('The Matrix', $candidate->attributes['title']);
        $this->assertSame('7321900219658', $candidate->attributes['ean']);
        $this->assertSame(136, $candidate->attributes['runtime_minutes']);
        $this->assertSame('English, German', $candidate->attributes['languages']);
        // GitHub issue #140.
        $this->assertSame('English, German', $candidate->attributes['subtitles']);
        $this->assertSame('Science Fiction', $candidate->attributes['genre']);
        $this->assertSame(1999, $candidate->attributes['production_year']);
        $this->assertSame([], $candidate->coverUrls);
    }

    public function test_lookup_by_code_returns_no_candidates_when_not_found(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->messagesResponse(['found' => false]), 200)]);

        $this->assertSame([], app(ClaudeDvdBlurayProvider::class)->lookupByCode('0000000000000'));
    }

    public function test_lookup_by_code_throws_when_the_request_fails(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response(['type' => 'error'], 500)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(ClaudeDvdBlurayProvider::class)->lookupByCode('7321900219658');
    }

    public function test_search_maps_items_without_a_known_ean(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->messagesResponse([
            'items' => [
                ['found' => true, 'title' => 'The Matrix Reloaded', 'description' => null, 'medium' => null, 'disc_count' => null, 'runtime_minutes' => null, 'languages' => null, 'subtitles' => null, 'cast' => null, 'director' => null, 'genre' => null, 'release_date' => null, 'production_year' => null],
            ],
        ]), 200)]);

        $candidates = app(ClaudeDvdBlurayProvider::class)->search('matrix reloaded');

        $this->assertCount(1, $candidates);
        $this->assertNull($candidates[0]->attributes['ean']);
        $this->assertSame('The Matrix Reloaded', $candidates[0]->attributes['title']);
        $this->assertSame('The Matrix Reloaded', $candidates[0]->sourceId);
    }

    public function test_source_type_is_llm_and_version_carries_a_beta_suffix(): void
    {
        $provider = app(ClaudeDvdBlurayProvider::class);

        $this->assertSame('llm', $provider->sourceType());
        $this->assertStringContainsString('beta', $provider->version());
    }
}
