<?php

namespace Tests\Feature;

use App\Domain\Metadata\MetadataProviderRequestException;
use App\Domain\Metadata\Providers\Book\GeminiBookProvider;
use App\Models\MetadataPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GeminiBookProvider (GitHub issue #66). Unlike HardcoverProvider/
 * DiscogsProvider's fixtures, these are hand-built to match the documented
 * generateContent + structured-output shape (verified against Google's
 * own current Gemini API reference while building this, not recalled from
 * training knowledge — see GeminiMetadataProvider's docblock), not real
 * captured responses — same disclosed-limitation precedent as
 * ClaudeBookProviderTest/OpenAiBookProviderTest for why this was never
 * live-verified either.
 */
class GeminiBookProviderTest extends TestCase
{
    use RefreshDatabase;

    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.7-flash:generateContent';

    private function configureApiKey(string $key = 'AIza-test-key'): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'book.gemini',
            'name' => 'Gemini',
            'media_type' => 'book',
            'enabled' => true,
            'config' => ['api_key' => $key],
        ]);
    }

    /** A structured-output response: one candidate whose content carries a single text part. */
    private function generateContentResponse(array $decodedJson): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            ['text' => json_encode($decodedJson)],
                        ],
                    ],
                    'finishReason' => 'STOP',
                    'index' => 0,
                ],
            ],
        ];
    }

    /** A fully blocked prompt: promptFeedback.blockReason is set, no candidates array at all — Gemini has no top-level stop_reason/refusal-content-item equivalent to Claude/OpenAI. */
    private function blockedPromptResponse(): array
    {
        return [
            'promptFeedback' => ['blockReason' => 'SAFETY'],
        ];
    }

    public function test_lookup_by_code_maps_a_found_item_to_a_candidate(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->generateContentResponse([
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

        $candidate = app(GeminiBookProvider::class)->lookupByCode('9780547928227')[0];

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
        Http::fake([self::API_URL => Http::response($this->generateContentResponse([
            'found' => false,
            'title' => null, 'authors' => null, 'description' => null, 'genre' => null, 'publisher' => null,
            'page_count' => null, 'language' => null, 'release_date' => null, 'format' => null,
            'isbn10' => null, 'isbn13' => null,
        ]), 200)]);

        $candidates = app(GeminiBookProvider::class)->lookupByCode('0000000000000');

        $this->assertSame([], $candidates);
    }

    /** GitHub issue #53: a failed request is reported as 'failed', not silently as 'no_match'. */
    public function test_lookup_by_code_throws_when_the_request_fails(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response(['error' => ['message' => 'API key not valid']], 400)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(GeminiBookProvider::class)->lookupByCode('9780547928227');
    }

    /** A fully blocked prompt surfaces as promptFeedback.blockReason with no candidates array — must still be reported as 'failed'. */
    public function test_lookup_by_code_throws_when_gemini_blocks_the_prompt(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->blockedPromptResponse(), 200)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(GeminiBookProvider::class)->lookupByCode('9780547928227');
    }

    /** A candidate-level (rather than prompt-level) safety stop leaves no usable text part — same generic "no text block" fallback OpenAI's "no message item" edge case falls into. */
    public function test_lookup_by_code_throws_when_no_text_part_is_present(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response([
            'candidates' => [
                ['content' => ['role' => 'model', 'parts' => []], 'finishReason' => 'SAFETY', 'index' => 0],
            ],
        ], 200)]);

        $this->expectException(MetadataProviderRequestException::class);
        app(GeminiBookProvider::class)->lookupByCode('9780547928227');
    }

    public function test_lookup_by_code_throws_when_no_api_key_is_configured(): void
    {
        Http::fake();

        $this->expectException(MetadataProviderRequestException::class);
        try {
            app(GeminiBookProvider::class)->lookupByCode('9780547928227');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_the_stored_key_is_sent_as_the_x_goog_api_key_header(): void
    {
        $this->configureApiKey('AIza-real-key');
        Http::fake([self::API_URL => Http::response($this->generateContentResponse(['found' => false]), 200)]);

        app(GeminiBookProvider::class)->lookupByCode('9780547928227');

        Http::assertSent(fn ($request) => $request->header('x-goog-api-key')[0] === 'AIza-real-key');
    }

    /** The googleSearch tool is declared on every request so the model can ground its answer in a real page, same reasoning ClaudeBookProviderTest's/OpenAiBookProviderTest's own identical checks document. */
    public function test_the_google_search_tool_is_declared_on_every_request(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->generateContentResponse(['found' => false]), 200)]);

        app(GeminiBookProvider::class)->lookupByCode('9780547928227');

        Http::assertSent(function ($request) {
            $tools = collect($request->data()['tools'] ?? []);

            return $tools->contains(fn ($tool) => array_key_exists('googleSearch', $tool));
        });
    }

    /** The admin-configured prompt (or its default) is sent as the separate top-level systemInstruction object — Gemini has no combined messages/input array the way Claude's system string/OpenAI's input array do. */
    public function test_the_configured_prompt_is_sent_as_the_system_instruction(): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'book.gemini',
            'name' => 'Gemini',
            'media_type' => 'book',
            'enabled' => true,
            'config' => ['api_key' => 'AIza-test-key', 'prompt' => 'Custom grounding instructions.'],
        ]);
        Http::fake([self::API_URL => Http::response($this->generateContentResponse(['found' => false]), 200)]);

        app(GeminiBookProvider::class)->lookupByCode('9780547928227');

        Http::assertSent(fn ($request) => $request->data()['systemInstruction']['parts'][0]['text'] === 'Custom grounding instructions.');
    }

    public function test_the_default_prompt_is_used_when_none_is_configured(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->generateContentResponse(['found' => false]), 200)]);

        app(GeminiBookProvider::class)->lookupByCode('9780547928227');

        Http::assertSent(fn ($request) => str_contains($request->data()['systemInstruction']['parts'][0]['text'], 'web search'));
    }

    public function test_search_maps_each_returned_item_to_a_candidate(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->generateContentResponse([
            'items' => [
                ['found' => true, 'title' => 'The Hobbit', 'authors' => 'J.R.R. Tolkien', 'description' => null, 'genre' => null, 'publisher' => null, 'page_count' => null, 'language' => null, 'release_date' => null, 'format' => null, 'isbn10' => null, 'isbn13' => '9780547928227'],
                ['found' => true, 'title' => 'The Fellowship of the Ring', 'authors' => 'J.R.R. Tolkien', 'description' => null, 'genre' => null, 'publisher' => null, 'page_count' => null, 'language' => null, 'release_date' => null, 'format' => null, 'isbn10' => null, 'isbn13' => null],
            ],
        ]), 200)]);

        $candidates = app(GeminiBookProvider::class)->search('tolkien');

        $this->assertCount(2, $candidates);
        $this->assertSame('The Hobbit', $candidates[0]->attributes['title']);
        $this->assertSame('9780547928227', $candidates[0]->sourceId);
        $this->assertSame('The Fellowship of the Ring', $candidates[1]->attributes['title']);
    }

    public function test_search_returns_empty_when_items_is_missing(): void
    {
        $this->configureApiKey();
        Http::fake([self::API_URL => Http::response($this->generateContentResponse([]), 200)]);

        $candidates = app(GeminiBookProvider::class)->search('nonexistent shape');

        $this->assertSame([], $candidates);
    }

    public function test_config_fields_declares_a_required_api_key_and_a_textarea_prompt_with_a_default(): void
    {
        $fields = app(GeminiBookProvider::class)->configFields();

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
        $provider = app(GeminiBookProvider::class);

        $this->assertSame('llm', $provider->sourceType());
        $this->assertStringContainsString('beta', $provider->version());
    }

    /** GitHub issue #66: Gemini is offered as a third LLM-backed source alongside Claude/ChatGPT — distinct provider key/name. */
    public function test_name_and_key_identify_this_as_the_gemini_provider(): void
    {
        $provider = app(GeminiBookProvider::class);

        $this->assertSame('Gemini', $provider->name());
        $this->assertSame('book.gemini', $provider->key());
    }
}
