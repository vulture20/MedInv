<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Book\OpenAiBookProvider;
use App\Models\MetadataPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpenAiBookProvider (GitHub issue #65). Unlike HardcoverProvider/
 * DiscogsProvider's fixtures, these are hand-built to match the documented
 * Responses API + structured-outputs shape (verified against OpenAI's own
 * current API reference while building this, not recalled from training
 * knowledge — see OpenAiMetadataProvider's docblock), not real captured
 * responses — same disclosed-limitation precedent as
 * ClaudeBookProviderTest for why this was never live-verified either.
 */
class OpenAiBookProviderTest extends TestCase
{
    use RefreshDatabase;

    private const API_URL = 'https://api.openai.com/v1/responses';

    private function configureApiKey(string $key = 'sk-test-key'): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'book.openai',
            'name' => 'ChatGPT',
            'media_type' => 'book',
            'enabled' => true,
            'config' => ['api_key' => $key],
        ]);
    }

    /** A structured-output response: `output` carries one message item whose content is a single output_text block. */
    private function responsesApiResponse(array $decodedJson): array
    {
        return [
            'id' => 'resp_test',
            'object' => 'response',
            'status' => 'completed',
            'output' => [
                [
                    'type' => 'message',
                    'id' => 'msg_test',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'output_text', 'text' => json_encode($decodedJson)],
                    ],
                ],
            ],
        ];
    }

    /** A declined request: the message's own content carries a "refusal" item instead of "output_text" — OpenAI has no top-level stop_reason equivalent to Claude's. */
    private function refusalResponse(): array
    {
        return [
            'id' => 'resp_test',
            'object' => 'response',
            'status' => 'completed',
            'output' => [
                [
                    'type' => 'message',
                    'id' => 'msg_test',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'refusal', 'refusal' => 'I cannot help with that.'],
                    ],
                ],
            ],
        ];
    }

    public function test_lookup_by_code_maps_a_found_item_to_a_candidate(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->responsesApiResponse([
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

        $candidate = app(OpenAiBookProvider::class)->lookupByCode('9780547928227')[0];

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
        Http::fake([self::API_URL => Http::response($this->responsesApiResponse([
            'found' => false,
            'title' => null, 'authors' => null, 'description' => null, 'genre' => null, 'publisher' => null,
            'page_count' => null, 'language' => null, 'release_date' => null, 'format' => null,
            'isbn10' => null, 'isbn13' => null,
        ]), 200)]);

        $candidates = app(OpenAiBookProvider::class)->lookupByCode('0000000000000');

        $this->assertSame([], $candidates);
    }

    /** GitHub issue #53: a failed request is reported as 'failed', not silently as 'no_match'. */
    public function test_lookup_by_code_throws_when_the_request_fails(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(OpenAiBookProvider::class)->lookupByCode('9780547928227');
    }

    /** A declined request surfaces as a "refusal"-type content item, not a top-level status flag — must still be reported as 'failed'. */
    public function test_lookup_by_code_throws_when_chatgpt_refuses(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->refusalResponse(), 200)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(OpenAiBookProvider::class)->lookupByCode('9780547928227');
    }

    /** Documented edge case: a response can stop early (e.g. status=incomplete on max_output_tokens) with only reasoning items emitted and no message item at all. */
    public function test_lookup_by_code_throws_when_no_message_item_is_present(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response([
            'id' => 'resp_test', 'object' => 'response', 'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [['type' => 'reasoning', 'id' => 'rs_test']],
        ], 200)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(OpenAiBookProvider::class)->lookupByCode('9780547928227');
    }

    public function test_lookup_by_code_throws_when_no_api_key_is_configured(): void
    {
        Http::fake();

        $this->expectException(MetadataProviderRequestException::class);
        try {
            app(OpenAiBookProvider::class)->lookupByCode('9780547928227');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_the_stored_key_is_sent_as_a_bearer_token(): void
    {
        $this->configureApiKey('sk-real-key');
        Http::fake([self::API_URL => Http::response($this->responsesApiResponse(['found' => false]), 200)]);

        app(OpenAiBookProvider::class)->lookupByCode('9780547928227');

        Http::assertSent(fn ($request) => $request->header('Authorization')[0] === 'Bearer sk-real-key');
    }

    /** The web_search tool is declared on every request so the model can ground its answer in a real page, same reasoning ClaudeBookProviderTest's own identical check documents. */
    public function test_the_web_search_tool_is_declared_on_every_request(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->responsesApiResponse(['found' => false]), 200)]);

        app(OpenAiBookProvider::class)->lookupByCode('9780547928227');

        Http::assertSent(function ($request) {
            $tools = collect($request->data()['tools'] ?? []);

            return $tools->contains(fn ($tool) => $tool['type'] === 'web_search');
        });
    }

    /** The admin-configured prompt (or its default) is sent as the system-role entry of `input` — the Responses API has no separate top-level `system` field the way Claude's Messages API does. */
    public function test_the_configured_prompt_is_sent_as_the_system_role_input_entry(): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'book.openai',
            'name' => 'ChatGPT',
            'media_type' => 'book',
            'enabled' => true,
            'config' => ['api_key' => 'sk-test-key', 'prompt' => 'Custom grounding instructions.'],
        ]);
        Http::fake([self::API_URL => Http::response($this->responsesApiResponse(['found' => false]), 200)]);

        app(OpenAiBookProvider::class)->lookupByCode('9780547928227');

        Http::assertSent(function ($request) {
            $systemEntry = collect($request->data()['input'])->firstWhere('role', 'system');

            return $systemEntry['content'] === 'Custom grounding instructions.';
        });
    }

    public function test_the_default_prompt_is_used_when_none_is_configured(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->responsesApiResponse(['found' => false]), 200)]);

        app(OpenAiBookProvider::class)->lookupByCode('9780547928227');

        Http::assertSent(function ($request) {
            $systemEntry = collect($request->data()['input'])->firstWhere('role', 'system');

            return str_contains($systemEntry['content'], 'web search');
        });
    }

    public function test_search_maps_each_returned_item_to_a_candidate(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->responsesApiResponse([
            'items' => [
                ['found' => true, 'title' => 'The Hobbit', 'authors' => 'J.R.R. Tolkien', 'description' => null, 'genre' => null, 'publisher' => null, 'page_count' => null, 'language' => null, 'release_date' => null, 'format' => null, 'isbn10' => null, 'isbn13' => '9780547928227'],
                ['found' => true, 'title' => 'The Fellowship of the Ring', 'authors' => 'J.R.R. Tolkien', 'description' => null, 'genre' => null, 'publisher' => null, 'page_count' => null, 'language' => null, 'release_date' => null, 'format' => null, 'isbn10' => null, 'isbn13' => null],
            ],
        ]), 200)]);

        $candidates = app(OpenAiBookProvider::class)->search('tolkien');

        $this->assertCount(2, $candidates);
        $this->assertSame('The Hobbit', $candidates[0]->attributes['title']);
        $this->assertSame('9780547928227', $candidates[0]->sourceId);
        $this->assertSame('The Fellowship of the Ring', $candidates[1]->attributes['title']);
    }

    public function test_search_returns_empty_when_items_is_missing(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->responsesApiResponse([]), 200)]);

        $candidates = app(OpenAiBookProvider::class)->search('nonexistent shape');

        $this->assertSame([], $candidates);
    }

    public function test_config_fields_declares_a_required_api_key_and_a_textarea_prompt_with_a_default(): void
    {
        $fields = app(OpenAiBookProvider::class)->configFields();

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
        $provider = app(OpenAiBookProvider::class);

        $this->assertSame('llm', $provider->sourceType());
        $this->assertStringContainsString('beta', $provider->version());
    }

    /** GitHub issue #65: OpenAI is offered as a second LLM-backed source alongside Claude — distinct provider key/name, same "ChatGPT" consumer-facing branding the issue itself refers to it by. */
    public function test_name_and_key_identify_this_as_the_openai_provider(): void
    {
        $provider = app(OpenAiBookProvider::class);

        $this->assertSame('ChatGPT', $provider->name());
        $this->assertSame('book.openai', $provider->key());
    }
}
