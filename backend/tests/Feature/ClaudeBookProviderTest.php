<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Book\ClaudeBookProvider;
use App\Models\MetadataPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ClaudeBookProvider (GitHub issue #59). Unlike HardcoverProvider/
 * DiscogsProvider's fixtures, these are hand-built to match the documented
 * Messages API + structured-outputs shape, not real captured responses —
 * see ClaudeMetadataProvider's docblock for why this one was never
 * live-verified.
 */
class ClaudeBookProviderTest extends TestCase
{
    use RefreshDatabase;

    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private function configureApiKey(string $key = 'sk-ant-test-key'): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'book.claude',
            'name' => 'Claude',
            'media_type' => 'book',
            'enabled' => true,
            'config' => ['api_key' => $key],
        ]);
    }

    /** A structured-output response: `content` carries one text block whose text is the JSON matching the requested schema. */
    private function messagesResponse(array $decodedJson, string $stopReason = 'end_turn'): array
    {
        return [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5',
            'stop_reason' => $stopReason,
            'content' => [
                ['type' => 'text', 'text' => json_encode($decodedJson)],
            ],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ];
    }

    public function test_lookup_by_code_maps_a_found_item_to_a_candidate(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->messagesResponse([
            'found' => true,
            'title' => 'The Hobbit',
            'authors' => 'J.R.R. Tolkien',
            'description' => 'A fantasy novel.',
            'genre' => 'Fantasy',
            'publisher' => 'Houghton Mifflin',
            'page_count' => 310,
            'language' => 'English',
            'release_date' => '1937-09-21',
            'format' => 'Hardcover',
            'isbn10' => '054792822X',
            'isbn13' => '9780547928227',
        ]), 200)]);

        $candidate = app(ClaudeBookProvider::class)->lookupByCode('9780547928227')[0];

        $this->assertSame('9780547928227', $candidate->sourceId);
        $this->assertSame('The Hobbit', $candidate->attributes['title']);
        $this->assertSame('J.R.R. Tolkien', $candidate->attributes['authors']);
        $this->assertSame(310, $candidate->attributes['page_count']);
        $this->assertSame('9780547928227', $candidate->attributes['isbn13']);
        $this->assertSame([], $candidate->coverUrls);
    }

    public function test_lookup_by_code_returns_no_candidates_when_the_model_reports_no_confident_match(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->messagesResponse([
            'found' => false,
            'title' => null,
            'authors' => null,
            'description' => null,
            'genre' => null,
            'publisher' => null,
            'page_count' => null,
            'language' => null,
            'release_date' => null,
            'format' => null,
            'isbn10' => null,
            'isbn13' => null,
        ]), 200)]);

        $candidates = app(ClaudeBookProvider::class)->lookupByCode('0000000000000');

        $this->assertSame([], $candidates);
    }

    /** GitHub issue #53: a failed request is reported as 'failed', not silently as 'no_match'. */
    public function test_lookup_by_code_throws_when_the_request_fails(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error']], 401)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(ClaudeBookProvider::class)->lookupByCode('9780547928227');
    }

    /** Claude Opus/Sonnet 5's safety classifiers can decline with a 200 + stop_reason: "refusal" rather than a non-2xx status — must still be reported as 'failed'. */
    public function test_lookup_by_code_throws_when_claude_refuses(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response([
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant',
            'stop_reason' => 'refusal', 'content' => [],
        ], 200)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(ClaudeBookProvider::class)->lookupByCode('9780547928227');
    }

    public function test_lookup_by_code_throws_when_no_api_key_is_configured(): void
    {
        Http::fake();

        $this->expectException(MetadataProviderRequestException::class);
        try {
            app(ClaudeBookProvider::class)->lookupByCode('9780547928227');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_the_stored_key_is_sent_as_the_x_api_key_header(): void
    {
        $this->configureApiKey('sk-ant-real-key');
        Http::fake([self::API_URL => Http::response($this->messagesResponse(['found' => false]), 200)]);

        app(ClaudeBookProvider::class)->lookupByCode('9780547928227');

        Http::assertSent(fn ($request) => $request->header('x-api-key')[0] === 'sk-ant-real-key'
            && $request->header('anthropic-version')[0] === '2023-06-01');
    }

    /** GitHub issue #59: the web_search tool is declared on every request so the model can ground its answer in a real page, per the addendum's own concern about hallucination. */
    public function test_the_web_search_tool_is_declared_on_every_request(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->messagesResponse(['found' => false]), 200)]);

        app(ClaudeBookProvider::class)->lookupByCode('9780547928227');

        Http::assertSent(function ($request) {
            $tools = collect($request->data()['tools'] ?? []);

            return $tools->contains(fn ($tool) => $tool['type'] === 'web_search_20260209');
        });
    }

    /** GitHub issue #59's addendum: the admin-configured prompt (or its default) is sent as the system prompt, grounding the request in real sources. */
    public function test_the_configured_prompt_is_sent_as_the_system_prompt(): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'book.claude',
            'name' => 'Claude',
            'media_type' => 'book',
            'enabled' => true,
            'config' => ['api_key' => 'sk-ant-test-key', 'prompt' => 'Custom grounding instructions.'],
        ]);
        Http::fake([self::API_URL => Http::response($this->messagesResponse(['found' => false]), 200)]);

        app(ClaudeBookProvider::class)->lookupByCode('9780547928227');

        Http::assertSent(fn ($request) => $request->data()['system'] === 'Custom grounding instructions.');
    }

    /** An admin who enabled this plugin without ever opening its settings dialog has no `prompt` in config — the request still carries a grounding instruction (the same default the field is pre-filled with). */
    public function test_the_default_prompt_is_used_when_none_is_configured(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->messagesResponse(['found' => false]), 200)]);

        app(ClaudeBookProvider::class)->lookupByCode('9780547928227');

        Http::assertSent(fn ($request) => str_contains($request->data()['system'], 'web search'));
    }

    public function test_search_maps_each_returned_item_to_a_candidate(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->messagesResponse([
            'items' => [
                ['found' => true, 'title' => 'The Hobbit', 'authors' => 'J.R.R. Tolkien', 'description' => null, 'genre' => null, 'publisher' => null, 'page_count' => null, 'language' => null, 'release_date' => null, 'format' => null, 'isbn10' => null, 'isbn13' => '9780547928227'],
                ['found' => true, 'title' => 'The Fellowship of the Ring', 'authors' => 'J.R.R. Tolkien', 'description' => null, 'genre' => null, 'publisher' => null, 'page_count' => null, 'language' => null, 'release_date' => null, 'format' => null, 'isbn10' => null, 'isbn13' => null],
            ],
        ]), 200)]);

        $candidates = app(ClaudeBookProvider::class)->search('tolkien');

        $this->assertCount(2, $candidates);
        $this->assertSame('The Hobbit', $candidates[0]->attributes['title']);
        $this->assertSame('9780547928227', $candidates[0]->sourceId);
        $this->assertSame('The Fellowship of the Ring', $candidates[1]->attributes['title']);
    }

    public function test_search_returns_empty_when_items_is_missing(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->messagesResponse([]), 200)]);

        $candidates = app(ClaudeBookProvider::class)->search('nonexistent shape');

        $this->assertSame([], $candidates);
    }

    public function test_config_fields_declares_a_required_api_key_and_a_textarea_prompt_with_a_default(): void
    {
        $fields = app(ClaudeBookProvider::class)->configFields();

        $this->assertSame('api_key', $fields[0]->key);
        $this->assertSame('password', $fields[0]->type);
        $this->assertTrue($fields[0]->required);

        $this->assertSame('prompt', $fields[1]->key);
        $this->assertSame('textarea', $fields[1]->type);
        $this->assertFalse($fields[1]->required);
        $this->assertNotEmpty($fields[1]->default);
    }

    public function test_source_type_is_llm_and_version_carries_a_beta_suffix(): void
    {
        $provider = app(ClaudeBookProvider::class);

        $this->assertSame('llm', $provider->sourceType());
        $this->assertStringContainsString('beta', $provider->version());
    }
}
