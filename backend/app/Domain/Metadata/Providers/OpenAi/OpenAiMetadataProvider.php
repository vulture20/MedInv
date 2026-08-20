<?php

namespace App\Domain\Metadata\Providers\OpenAi;

use App\Domain\Metadata\Contracts\MetadataProviderConfigField;
use App\Domain\Metadata\MetadataProviderRequestException;
use App\Models\MetadataPlugin;
use Illuminate\Support\Facades\Http;

/**
 * Shared logic for the three OpenAI-backed metadata providers (book/cd/
 * dvd_bluray, GitHub issue #65) — the same LLM-as-metadata-source concept
 * ClaudeMetadataProvider already implements (GitHub issue #59, "Claude,
 * ChatGPT, …" named as examples there), offered as a second option since
 * an operator may already have — or prefer — an OpenAI API key over an
 * Anthropic one. Structural mirror of ClaudeMetadataProvider in every way
 * that isn't OpenAI-specific: same risk posture (no cover/price fields,
 * web-search grounding + an admin-editable prompt, Beta/opt-in, medium
 * reasoning effort for a bounded extraction task rather than open-ended
 * reasoning) — see that class's own docblock for the reasoning behind all
 * of that, not repeated here.
 *
 * What's genuinely different from Claude, verified against OpenAI's own
 * current API reference/guides before writing a single line here (per
 * this issue's own explicit caution — training knowledge for a
 * fast-moving API surface isn't trustworthy enough on its own, the same
 * care #59 already documented for Claude):
 *
 *  - **Endpoint & auth.** `POST https://api.openai.com/v1/responses`,
 *    `Authorization: Bearer <api_key>` — no extra version header (unlike
 *    Claude's `anthropic-version`).
 *  - **One `input` array, not a separate `system` + `messages` split.**
 *    The Responses API takes a single `input` array of `{role, content}`
 *    turns, `system` included as just another entry in it, rather than
 *    Claude's top-level `system` string alongside a separate `messages`
 *    array.
 *  - **Structured outputs live under `text.format`, not `output_config.
 *    format`**, and additionally require a `name` identifier for the
 *    schema (`type: 'json_schema', name, strict: true, schema`) — Claude
 *    has no equivalent "name" requirement. The schema shape itself
 *    (every property listed in `required`, nullable ones typed as
 *    `[type, "null"]`, `additionalProperties: false` at every object
 *    level) turned out to already match what OpenAI's own `strict: true`
 *    mode requires, so the schema-building logic below is otherwise the
 *    same shape ClaudeMetadataProvider's own singleItemSchema()/
 *    itemListSchema()/fieldSchemas() already use.
 *  - **Web search tool**: `{"type": "web_search"}` — a single flat type
 *    string, not a versioned tool name + explicit `name`/`max_uses` pair
 *    the way Claude's `web_search_20260209` tool declaration needs.
 *  - **Reasoning effort** is a top-level `reasoning: {effort: 'medium'}`
 *    object, not nested under the structured-output config the way
 *    Claude's `effort` is.
 *  - **Refusal detection is structural, not a top-level status flag.**
 *    Claude signals a decline via a top-level `stop_reason: "refusal"`.
 *    OpenAI's Responses API instead emits a `content` item of type
 *    `"refusal"` (carrying the decline text) inside the same `message`
 *    output item that would otherwise carry `"output_text"` — checked for
 *    explicitly below, alongside the (also real, per OpenAI's own
 *    community-documented behavior) case of no `message`-type output
 *    item existing at all, e.g. when a response stops early on
 *    max_output_tokens with only reasoning items emitted.
 *  - **Model**: `gpt-5.6-terra` — OpenAI's own pricing/model documentation
 *    describes this specific tier as its mid-range option within the
 *    GPT-5.6 family (a cheaper `-luna` tier and a pricier flagship
 *    `-sol` tier also exist), the same "not the cheapest, not the
 *    flagship, right-sized for a bounded extraction/lookup task"
 *    positioning ClaudeMetadataProvider's own docblock explains for
 *    choosing Sonnet over Haiku/Opus.
 *
 * Like ClaudeMetadataProvider, this was never live-verified against the
 * real OpenAI API — this sandbox has network access to api.openai.com but
 * no credentials to authenticate with. Built and tested only against the
 * documented request/response shapes above (each individually confirmed
 * by fetching OpenAI's own current API reference/guide pages while
 * writing this, not recalled from training data) and hand-built fixtures
 * modeled on them.
 */
trait OpenAiMetadataProvider
{
    private const API_URL = 'https://api.openai.com/v1/responses';

    /** See this trait's own docblock for why gpt-5.6-terra specifically. */
    private const MODEL = 'gpt-5.6-terra';

    /** Same grounding-instruction wording ClaudeMetadataProvider::DEFAULT_PROMPT uses — the instruction itself isn't model-specific, only how it's transmitted (a `system`-role entry in `input` here, vs. a top-level `system` string for Claude) is. */
    private const DEFAULT_PROMPT = <<<'PROMPT'
        When looking up this item, prioritize information you can verify from real, publicly accessible sources — online retailers (e.g. JPC, Thalia, Amazon), the publisher's or label's own website, or established databases (e.g. Discogs, IMDb, WorldCat) — over answering purely from your own training knowledge. Use web search to check a real source before answering. If you cannot find reliable information from such a source, say so honestly (set "found" to false, or omit the item) rather than guessing or inventing details.
        PROMPT;

    public function name(): string
    {
        return 'ChatGPT';
    }

    /** Same "v0.1-beta" free-form versioning ClaudeMetadataProvider's own docblock explains (GitHub issue #44/#52). */
    public function version(): string
    {
        return 'v0.1-beta';
    }

    /** Same 'llm' category ClaudeMetadataProvider introduced (GitHub issue #59) — see MetadataProviderInterface::sourceType()'s own docblock. */
    public function sourceType(): string
    {
        return 'llm';
    }

    /** Identical shape to ClaudeMetadataProvider::configFields() — same two fields, same reasoning. */
    public function configFields(): array
    {
        return [
            new MetadataProviderConfigField('api_key', type: 'password', required: true),
            new MetadataProviderConfigField('prompt', type: 'textarea', required: false, default: self::DEFAULT_PROMPT),
        ];
    }

    /**
     * Runs one grounded lookup for a single, specific real-world item and
     * returns the decoded item object, or null when the model reported no
     * confident match (`found: false`) — same contract as
     * ClaudeMetadataProvider::lookupSingleItem().
     *
     * @param  array<string, string|array>  $itemFields  Field key => either a bare JSON Schema type name (e.g. "string", "integer") or a full property schema fragment for anything more than a nullable scalar (e.g. tracks' nested array-of-objects shape).
     */
    protected function lookupSingleItem(string $prompt, array $itemFields): ?array
    {
        $decoded = $this->requestJson($prompt, 'metadata_item', $this->singleItemSchema($itemFields));

        return ($decoded['found'] ?? false) ? $decoded : null;
    }

    /**
     * Runs one grounded free-text search and returns the decoded list of
     * item objects (possibly empty) — same contract as
     * ClaudeMetadataProvider::searchItems().
     *
     * @param  array<string, string|array>  $itemFields  See lookupSingleItem()'s docblock.
     * @return array<int, array<string, mixed>>
     */
    protected function searchItems(string $prompt, array $itemFields): array
    {
        $decoded = $this->requestJson($prompt, 'metadata_list', $this->itemListSchema($itemFields));
        $items = $decoded['items'] ?? null;

        return is_array($items) ? $items : [];
    }

    /**
     * The actual Responses API round trip — see this trait's own docblock
     * for exactly how this differs from ClaudeMetadataProvider::
     * requestJson(), point by point.
     */
    private function requestJson(string $userPrompt, string $schemaName, array $schema): array
    {
        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            throw new MetadataProviderRequestException('ChatGPT request failed (missing api_key).');
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
        ])->timeout(60)->post(self::API_URL, [
            'model' => self::MODEL,
            'input' => [
                ['role' => 'system', 'content' => $this->promptPreamble()],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'tools' => [
                ['type' => 'web_search'],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $schemaName,
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
            // A bounded extraction/lookup task, not open-ended reasoning —
            // see this trait's own docblock on model choice for the same
            // cost-consciousness applied to effort.
            'reasoning' => ['effort' => 'medium'],
        ]);

        if ($response->failed()) {
            throw new MetadataProviderRequestException("ChatGPT request failed: HTTP {$response->status()}.");
        }

        $message = collect($response->json('output', []))->firstWhere('type', 'message');

        // Covers both a genuine refusal-shaped response and the documented
        // "stopped early, only reasoning items emitted, no message item at
        // all" edge case (e.g. status=incomplete on max_output_tokens) —
        // either way, there's no usable output_text to extract.
        if (! is_array($message)) {
            throw new MetadataProviderRequestException('ChatGPT response did not include a message.');
        }

        $content = collect($message['content'] ?? []);

        // A declined request surfaces as a "refusal"-type content item
        // alongside (instead of) "output_text" — unlike Claude, there's no
        // top-level stop_reason to check instead.
        if ($content->contains(fn (array $item) => ($item['type'] ?? null) === 'refusal')) {
            throw new MetadataProviderRequestException('ChatGPT declined to answer this request.');
        }

        $text = $content->firstWhere('type', 'output_text')['text'] ?? null;

        if (! is_string($text)) {
            throw new MetadataProviderRequestException('ChatGPT response did not include a text block.');
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new MetadataProviderRequestException('ChatGPT response was not valid JSON.');
        }

        return $decoded;
    }

    /** Identical shape to ClaudeMetadataProvider::singleItemSchema() — see this trait's own docblock for why the same schema-building logic works unchanged for OpenAI's strict-mode structured outputs too. */
    private function singleItemSchema(array $itemFields): array
    {
        ['properties' => $properties, 'required' => $required] = $this->fieldSchemas($itemFields);

        return [
            'type' => 'object',
            'properties' => ['found' => ['type' => 'boolean'], ...$properties],
            'required' => ['found', ...$required],
            'additionalProperties' => false,
        ];
    }

    /** Identical shape to ClaudeMetadataProvider::itemListSchema(). */
    private function itemListSchema(array $itemFields): array
    {
        ['properties' => $properties, 'required' => $required] = $this->fieldSchemas($itemFields);

        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => $properties,
                        'required' => $required,
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['items'],
            'additionalProperties' => false,
        ];
    }

    /** Identical shape to ClaudeMetadataProvider::fieldSchemas(). */
    private function fieldSchemas(array $itemFields): array
    {
        $properties = [];
        $required = [];

        foreach ($itemFields as $key => $type) {
            $properties[$key] = is_array($type) ? $type : ['type' => [$type, 'null']];
            $required[] = $key;
        }

        return ['properties' => $properties, 'required' => $required];
    }

    /** Same fallback reasoning as ClaudeMetadataProvider::promptPreamble(). */
    private function promptPreamble(): string
    {
        $prompt = $this->config()['prompt'] ?? null;

        return is_string($prompt) && trim($prompt) !== '' ? $prompt : self::DEFAULT_PROMPT;
    }

    /** Same runtime-configured-secret pattern as ClaudeMetadataProvider::apiKey()/UpcMdbProvider::apiKey(). */
    private function apiKey(): ?string
    {
        $key = $this->config()['api_key'] ?? null;

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    private function config(): array
    {
        return MetadataPlugin::query()->where('provider_key', $this->key())->first()?->config ?? [];
    }
}
