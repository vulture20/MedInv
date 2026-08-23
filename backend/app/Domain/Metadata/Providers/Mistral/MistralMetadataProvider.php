<?php

namespace App\Domain\Metadata\Providers\Mistral;

use App\Domain\Metadata\Contracts\MetadataProviderConfigField;
use App\Domain\Metadata\MetadataProviderRequestException;
use App\Models\MetadataPlugin;
use Illuminate\Support\Facades\Http;

/**
 * Shared logic for the three Mistral-backed metadata providers (book/cd/
 * dvd_bluray, GitHub issue #68) — a fourth vendor for the same
 * LLM-as-metadata-source concept ClaudeMetadataProvider (#59)/
 * OpenAiMetadataProvider (#65)/GeminiMetadataProvider (#66) already
 * implement. Same risk posture as all three (no cover/price fields,
 * web-search grounding + an admin-editable prompt, Beta/opt-in, a
 * "not the cheapest, not the flagship" model tier for a bounded extraction
 * task) — see ClaudeMetadataProvider's own docblock for the reasoning
 * behind all of that, not repeated here.
 *
 * GitHub issue #68's own technical note flagged the real open question
 * up front: whether a web-search tool attaches to the same simple
 * chat-completions-style request Claude/OpenAI/Gemini all use, or needs a
 * separate, heavier API. Verified against Mistral's own current
 * documentation before writing a single line here (same caution #59/#65/
 * #66 already documented) — the answer turned out to be "both, in a way":
 *
 *  - **`web_search`/`web_search_premium` are NOT functional on
 *    `POST /v1/chat/completions`**, confirmed by two independent official
 *    doc pages stating, word for word: "web_search, web_search_premium ...
 *    work with the Conversations API (/v1/conversations) and the Agents
 *    API. They aren't supported in the Chat Completions API." (A third
 *    reference page's request-schema listing for `/v1/chat/completions`
 *    does list `WebSearchTool` as one of several accepted `tools` item
 *    types — apparently a shared type across endpoints in Mistral's own
 *    OpenAPI spec, not an indication it actually executes there; the two
 *    explicit prose statements are trusted over that schema listing.)
 *  - **But `POST /v1/conversations` does *not* require the heavier,
 *    stateful "create an Agent resource first" workflow** its own Agents
 *    API guide leads with — `agent_id` is documented as optional
 *    (`string|null`), and Mistral's own docs describe starting a
 *    conversation by "directly specifying the model and completion
 *    parameters" as a first-class alternative to a pre-created agent. So
 *    this trait calls `/v1/conversations` inline, one request per lookup,
 *    the same one-call-per-lookup shape every other LLM provider here
 *    uses — just a different endpoint/response envelope than Claude's
 *    Messages API, OpenAI's Responses API, or Gemini's `generateContent`.
 *  - **Endpoint & auth.** `POST https://api.mistral.ai/v1/conversations`,
 *    `Authorization: Bearer <api_key>` — the same bearer-token convention
 *    every other Mistral API surface documented during this research uses
 *    (no vendor-specific extra header, unlike Claude's `anthropic-version`
 *    or Gemini's `x-goog-api-key`).
 *  - **Request shape**: `model` (a plain string, `mistral-medium-latest`
 *    below — the exact model Mistral's own official web-search example
 *    uses, and the "not cheapest, not flagship" tier this app's other LLM
 *    traits already favor for a bounded extraction task), `inputs` (the
 *    user-turn text, confirmed to accept a plain string), `instructions`
 *    (a separate top-level string for the grounding preamble — closer in
 *    shape to Claude's top-level `system` than OpenAI's combined `input`
 *    array), `tools` (`[{"type": "web_search"}]`, confirmed a real,
 *    documented built-in tool type for this endpoint specifically), and
 *    `completion_args` — documented only as "white-listed arguments from
 *    the completion API", i.e. a subset of `/v1/chat/completions`' own
 *    request fields (confirmed to include `response_format`, itself
 *    confirmed to support a `"json_schema"` type on the chat-completions
 *    endpoint) nested one level deeper here.
 *  - **Response shape**: an `outputs` array of typed entries
 *    (`message.output`, `tool_execution`, `function_call`,
 *    `agent_handoff`, per the endpoint reference) — this trait looks for
 *    the entry with `type === "message.output"` (confirmed via Mistral's
 *    own documented response-parsing example) and reads its `content`,
 *    documented as either a plain string or an array of content chunks
 *    each carrying their own `text` — both shapes are handled below.
 *
 * **The one real, disclosed gap** (per this issue's own explicit
 * instruction: an honestly documented limitation, not a pretended
 * capability, when something can't be confirmed) — whether
 * `completion_args.response_format` with `type: "json_schema"` is
 * actually part of that "white-listed" subset, and specifically whether
 * it's usable *together* with the `web_search` tool in the same request,
 * is **not confirmed** by any Mistral documentation reachable during this
 * research; the Conversations-API-specific structured-output reference
 * page that should settle this did not actually cover the Conversations
 * API at all (only chat completions). This trait sends
 * `completion_args.response_format` in the same
 * `{"type": "json_schema", "json_schema": {"name", "schema"}}` shape
 * `/v1/chat/completions` itself confirms, on the reasoning that a
 * "white-listed" subset of the completions API most plausibly reuses that
 * API's own field shapes rather than inventing a new one — but this is
 * an inference, not a confirmed fact. If Mistral's API actually rejects
 * this combination, a request fails with a non-2xx status, which
 * `requestJson()` below already turns into an ordinary
 * `MetadataProviderRequestException` — surfaced to the user as a normal
 * per-provider `'failed'` status (GitHub issue #53), exactly like any
 * other genuine request failure, never silently swallowed or
 * misrepresented as "no match".
 *
 * Like Claude/OpenAI/Gemini, this was never live-verified against the
 * real Mistral API — this sandbox has network access to api.mistral.ai
 * but no credentials to authenticate with. Built and tested only against
 * the documented request/response shapes above (each individually
 * confirmed by fetching Mistral's own current documentation while writing
 * this, not recalled from training data, except the one explicitly
 * disclosed gap above) and hand-built fixtures modeled on them.
 */
trait MistralMetadataProvider
{
    private const API_URL = 'https://api.mistral.ai/v1/conversations';

    /** See this trait's own docblock for why mistral-medium-latest specifically. */
    private const MODEL = 'mistral-medium-latest';

    /** Same grounding-instruction wording Claude's/OpenAI's/Gemini's DEFAULT_PROMPT use — the instruction itself isn't model-specific, only how it's transmitted (a top-level `instructions` string here) is. */
    private const DEFAULT_PROMPT = <<<'PROMPT'
        When looking up this item, prioritize information you can verify from real, publicly accessible sources — online retailers (e.g. JPC, Thalia, Amazon), the publisher's or label's own website, or established databases (e.g. Discogs, IMDb, WorldCat) — over answering purely from your own training knowledge. Use web search to check a real source before answering. If you cannot find reliable information from such a source, say so honestly (set "found" to false, or omit the item) rather than guessing or inventing details.
        PROMPT;

    public function name(): string
    {
        return 'Mistral';
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

    /** Same as ClaudeMetadataProvider::supportsCodeLookup() — see MetadataProviderInterface::supportsCodeLookup()'s own docblock (GitHub issue #158). */
    public function supportsCodeLookup(): bool
    {
        return true;
    }

    /** Identical shape to ClaudeMetadataProvider::configFields()/OpenAiMetadataProvider::configFields()/GeminiMetadataProvider::configFields() — same two fields, same reasoning. */
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
     * The actual Conversations API round trip — see this trait's own
     * docblock for the full request/response shape and the one disclosed,
     * unconfirmed assumption (response_format + web_search combined).
     */
    private function requestJson(string $userPrompt, string $schemaName, array $schema): array
    {
        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            throw new MetadataProviderRequestException('Mistral request failed (missing api_key).');
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
        ])->timeout(60)->post(self::API_URL, [
            'model' => self::MODEL,
            'inputs' => $userPrompt,
            'instructions' => $this->promptPreamble(),
            'tools' => [
                ['type' => 'web_search'],
            ],
            // See this trait's own docblock: completion_args is documented
            // only as "white-listed arguments from the completion API" —
            // response_format itself is confirmed on /v1/chat/completions,
            // but whether it's part of that whitelist, and whether it
            // combines with the web_search tool above, is not confirmed.
            'completion_args' => [
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => $schemaName,
                        'schema' => $schema,
                    ],
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new MetadataProviderRequestException("Mistral request failed: HTTP {$response->status()}.");
        }

        // Mistral's docs never surfaced a distinct "declined to answer"
        // status the way Claude's stop_reason/OpenAI's refusal content
        // item/Gemini's promptFeedback.blockReason do — an absent or
        // textless message.output entry (checked below) is the one
        // generic "nothing usable came back" case this trait can actually
        // confirm, covering a genuine decline the same way it covers any
        // other "no text in the response" shape.
        $message = collect($response->json('outputs', []))->first(fn (array $item) => ($item['type'] ?? null) === 'message.output');

        if (! is_array($message)) {
            throw new MetadataProviderRequestException('Mistral response did not include a message output.');
        }

        $content = $message['content'] ?? null;
        $text = match (true) {
            is_string($content) => $content,
            is_array($content) => collect($content)->pluck('text')->filter(fn ($t) => is_string($t))->implode(''),
            default => null,
        };

        if (! is_string($text) || $text === '') {
            throw new MetadataProviderRequestException('Mistral response did not include a text block.');
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new MetadataProviderRequestException('Mistral response was not valid JSON.');
        }

        return $decoded;
    }

    /** Identical shape to ClaudeMetadataProvider::singleItemSchema()/OpenAiMetadataProvider::singleItemSchema() — plain JSON Schema, the shape Mistral's own structured-output docs describe ("supplying a clear JSON schema"), unlike Gemini's OpenAPI-subset dialect. */
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
