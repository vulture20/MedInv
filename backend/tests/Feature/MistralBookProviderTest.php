<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Book\MistralBookProvider;
use App\Models\MetadataPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MistralBookProvider (GitHub issue #68). Like ClaudeBookProviderTest/
 * OpenAiBookProviderTest/GeminiBookProviderTest, these fixtures are
 * hand-built to match the documented Conversations API shape (verified
 * against Mistral's own current documentation while building this, not
 * recalled from training knowledge — see MistralMetadataProvider's own
 * docblock, including its one disclosed, unconfirmed assumption: whether
 * completion_args.response_format actually combines with the web_search
 * tool), not real captured responses.
 */
class MistralBookProviderTest extends TestCase
{
    use RefreshDatabase;

    private const API_URL = 'https://api.mistral.ai/v1/conversations';

    private function configureApiKey(string $key = 'test-key'): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'book.mistral',
            'name' => 'Mistral',
            'media_type' => 'book',
            'enabled' => true,
            'config' => ['api_key' => $key],
        ]);
    }

    /** A structured-output response: `outputs` carries one message.output entry whose content is a plain JSON string, per Mistral's own documented response-parsing example. */
    private function conversationsApiResponse(array $decodedJson): array
    {
        return [
            'conversation_id' => 'conv_test',
            'outputs' => [
                ['type' => 'message.output', 'content' => json_encode($decodedJson)],
            ],
        ];
    }

    /** Mistral's docs also show `content` as an array of chunks each carrying their own `text`, not just a plain string — both shapes must be handled (see MistralMetadataProvider::requestJson()). */
    private function conversationsApiResponseWithChunkedContent(array $decodedJson): array
    {
        return [
            'conversation_id' => 'conv_test',
            'outputs' => [
                ['type' => 'message.output', 'content' => [['type' => 'text', 'text' => json_encode($decodedJson)]]],
            ],
        ];
    }

    public function test_lookup_by_code_maps_a_found_item_to_a_candidate(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->conversationsApiResponse([
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

        $candidate = app(MistralBookProvider::class)->lookupByCode('9780547928227')[0];

        $this->assertSame('9780547928227', $candidate->sourceId);
        $this->assertSame('The Hobbit', $candidate->attributes['title']);
        $this->assertSame('J.R.R. Tolkien', $candidate->attributes['authors']);
        $this->assertSame(310, $candidate->attributes['page_count']);
        $this->assertSame('9780547928227', $candidate->attributes['isbn13']);
        $this->assertSame([], $candidate->coverUrls);
    }

    /** See conversationsApiResponseWithChunkedContent()'s own docblock. */
    public function test_lookup_by_code_handles_chunked_array_content_too(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->conversationsApiResponseWithChunkedContent([
            'found' => true, 'title' => 'The Hobbit', 'authors' => null, 'description' => null, 'genre' => null,
            'publisher' => null, 'page_count' => null, 'language' => null, 'release_date' => null, 'format' => null,
            'isbn10' => null, 'isbn13' => null,
        ]), 200)]);

        $candidate = app(MistralBookProvider::class)->lookupByCode('9780547928227')[0];

        $this->assertSame('The Hobbit', $candidate->attributes['title']);
    }

    public function test_lookup_by_code_returns_no_candidates_when_the_model_reports_no_confident_match(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->conversationsApiResponse([
            'found' => false,
            'title' => null, 'authors' => null, 'description' => null, 'genre' => null, 'publisher' => null,
            'page_count' => null, 'language' => null, 'release_date' => null, 'format' => null,
            'isbn10' => null, 'isbn13' => null,
        ]), 200)]);

        $candidates = app(MistralBookProvider::class)->lookupByCode('0000000000000');

        $this->assertSame([], $candidates);
    }

    /** GitHub issue #53: a failed request is reported as 'failed', not silently as 'no_match' — also exercises the one disclosed, unconfirmed assumption in this trait's docblock: a rejected response_format+web_search combination would surface exactly this way, not silently. */
    public function test_lookup_by_code_throws_when_the_request_fails(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response(['message' => 'Invalid API key'], 401)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(MistralBookProvider::class)->lookupByCode('9780547928227');
    }

    /** Mistral's docs never documented a distinct "declined to answer" shape (unlike Claude/OpenAI/Gemini) — an absent message.output entry is the one generic "nothing usable" case this trait can actually confirm. */
    public function test_lookup_by_code_throws_when_no_message_output_entry_is_present(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response([
            'conversation_id' => 'conv_test',
            'outputs' => [['type' => 'tool_execution', 'name' => 'web_search']],
        ], 200)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(MistralBookProvider::class)->lookupByCode('9780547928227');
    }

    public function test_lookup_by_code_throws_when_no_api_key_is_configured(): void
    {
        Http::fake();

        $this->expectException(MetadataProviderRequestException::class);
        try {
            app(MistralBookProvider::class)->lookupByCode('9780547928227');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_the_stored_key_is_sent_as_a_bearer_token(): void
    {
        $this->configureApiKey('real-key');
        Http::fake([self::API_URL => Http::response($this->conversationsApiResponse(['found' => false]), 200)]);

        app(MistralBookProvider::class)->lookupByCode('9780547928227');

        Http::assertSent(fn ($request) => $request->header('Authorization')[0] === 'Bearer real-key');
    }

    /** The web_search tool is declared on every request so the model can ground its answer in a real page, same reasoning ClaudeBookProviderTest's own identical check documents. */
    public function test_the_web_search_tool_is_declared_on_every_request(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->conversationsApiResponse(['found' => false]), 200)]);

        app(MistralBookProvider::class)->lookupByCode('9780547928227');

        Http::assertSent(function ($request) {
            $tools = collect($request->data()['tools'] ?? []);

            return $tools->contains(fn ($tool) => $tool['type'] === 'web_search');
        });
    }

    /** The structured-output schema is nested under completion_args.response_format — see this trait's own docblock for why (Conversations API's completion_args being a "white-listed" subset of chat completions' own fields). */
    public function test_the_structured_output_schema_is_nested_under_completion_args(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->conversationsApiResponse(['found' => false]), 200)]);

        app(MistralBookProvider::class)->lookupByCode('9780547928227');

        Http::assertSent(function ($request) {
            $format = $request->data()['completion_args']['response_format'] ?? null;

            return ($format['type'] ?? null) === 'json_schema' && ($format['json_schema']['name'] ?? null) === 'metadata_item';
        });
    }

    /** The admin-configured prompt (or its default) is sent as the top-level `instructions` field — Mistral's Conversations API has no separate `messages`/`input` array the way Claude/OpenAI do. */
    public function test_the_configured_prompt_is_sent_as_the_instructions_field(): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'book.mistral',
            'name' => 'Mistral',
            'media_type' => 'book',
            'enabled' => true,
            'config' => ['api_key' => 'test-key', 'prompt' => 'Custom grounding instructions.'],
        ]);
        Http::fake([self::API_URL => Http::response($this->conversationsApiResponse(['found' => false]), 200)]);

        app(MistralBookProvider::class)->lookupByCode('9780547928227');

        Http::assertSent(fn ($request) => $request->data()['instructions'] === 'Custom grounding instructions.');
    }

    public function test_the_default_prompt_is_used_when_none_is_configured(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->conversationsApiResponse(['found' => false]), 200)]);

        app(MistralBookProvider::class)->lookupByCode('9780547928227');

        Http::assertSent(fn ($request) => str_contains($request->data()['instructions'], 'web search'));
    }

    public function test_search_maps_each_returned_item_to_a_candidate(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->conversationsApiResponse([
            'items' => [
                ['found' => true, 'title' => 'The Hobbit', 'authors' => 'J.R.R. Tolkien', 'description' => null, 'genre' => null, 'publisher' => null, 'page_count' => null, 'language' => null, 'release_date' => null, 'format' => null, 'isbn10' => null, 'isbn13' => '9780547928227'],
                ['found' => true, 'title' => 'The Fellowship of the Ring', 'authors' => 'J.R.R. Tolkien', 'description' => null, 'genre' => null, 'publisher' => null, 'page_count' => null, 'language' => null, 'release_date' => null, 'format' => null, 'isbn10' => null, 'isbn13' => null],
            ],
        ]), 200)]);

        $candidates = app(MistralBookProvider::class)->search('tolkien');

        $this->assertCount(2, $candidates);
        $this->assertSame('The Hobbit', $candidates[0]->attributes['title']);
        $this->assertSame('9780547928227', $candidates[0]->sourceId);
        $this->assertSame('The Fellowship of the Ring', $candidates[1]->attributes['title']);
    }

    public function test_search_returns_empty_when_items_is_missing(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->conversationsApiResponse([]), 200)]);

        $candidates = app(MistralBookProvider::class)->search('nonexistent shape');

        $this->assertSame([], $candidates);
    }

    public function test_config_fields_declares_a_required_api_key_and_a_textarea_prompt_with_a_default(): void
    {
        $fields = app(MistralBookProvider::class)->configFields();

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
        $provider = app(MistralBookProvider::class);

        $this->assertSame('llm', $provider->sourceType());
        $this->assertStringContainsString('beta', $provider->version());
    }

    /** GitHub issue #68: Mistral is offered as a fourth LLM-backed source alongside Claude/OpenAI/Gemini — distinct provider key/name. */
    public function test_name_and_key_identify_this_as_the_mistral_provider(): void
    {
        $provider = app(MistralBookProvider::class);

        $this->assertSame('Mistral', $provider->name());
        $this->assertSame('book.mistral', $provider->key());
    }
}
