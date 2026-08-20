<?php

namespace App\Domain\Metadata\Providers\Gemini;

use App\Domain\Metadata\Contracts\MetadataProviderConfigField;
use App\Domain\Metadata\MetadataProviderRequestException;
use App\Models\MetadataPlugin;
use Illuminate\Support\Facades\Http;

/**
 * Shared logic for the three Gemini-backed metadata providers (book/cd/
 * dvd_bluray, GitHub issue #66) — a third vendor for the same
 * LLM-as-metadata-source concept ClaudeMetadataProvider (#59) and
 * OpenAiMetadataProvider (#65) already implement, offered because an
 * operator may already have — or prefer — a Google AI Studio key over an
 * Anthropic or OpenAI one. Structural mirror of both in every way that
 * isn't Gemini-specific: same risk posture (no cover/price fields,
 * web-search grounding + an admin-editable prompt, Beta/opt-in, a
 * "not the cheapest, not the flagship" model tier for a bounded
 * extraction task) — see ClaudeMetadataProvider's own docblock for the
 * reasoning behind all of that, not repeated here.
 *
 * The issue's own technical note steered this towards the simple AI
 * Studio key + Generative Language API surface, not the fuller Vertex AI
 * integration (GCP project, service account, OAuth) — deliberately, to
 * keep this provider's admin-facing setup at the same "one api_key field"
 * level as every other provider in this app, Claude/OpenAI included.
 *
 * What's genuinely different from Claude/OpenAI, verified against
 * Google's own current Gemini API reference/guides before writing a
 * single line here (per the same caution #59/#65 already documented —
 * training knowledge for a fast-moving API surface isn't trustworthy
 * enough on its own):
 *
 *  - **Endpoint & auth.** `POST https://generativelanguage.googleapis.com/
 *    v1beta/models/{model}:generateContent`, with the key sent as an
 *    `x-goog-api-key` header — no `Bearer`/`x-api-key` scheme, and the
 *    model name is part of the URL path itself, not a body field. This is
 *    the documented-stable `generateContent` REST method; Google's own
 *    docs point new integrations at a newer "Interactions API" for
 *    "access to all the latest features", but explicitly still recommend
 *    `generateContent` "for stable production deployments" — the more
 *    fitting choice for a self-hosted app's plugin, the same reasoning
 *    that already led Claude/OpenAI's own traits to each vendor's
 *    established Messages/Responses API rather than an experimental one.
 *  - **`contents`/`systemInstruction`, not `messages`/`system` or
 *    `input`.** The request body's user turn is a `contents` array of
 *    `{role, parts: [{text}]}` objects (`parts` always an array, even for
 *    plain text); the grounding instruction is a separate top-level
 *    `systemInstruction` object (`{parts: [{text}]}`, no `role` needed)
 *    — closer in shape to Claude's separate top-level `system` string
 *    than to OpenAI's single combined `input` array, but wrapped one
 *    level deeper (`parts[].text` rather than a bare string).
 *  - **Structured outputs live under `generationConfig.responseMimeType`
 *    + `generationConfig.responseSchema`**, two sibling fields, not a
 *    single nested `format`/`output_config` object the way Claude/OpenAI
 *    both use. `responseSchema` is documented as a subset of the OpenAPI
 *    3.0 Schema Object, not plain JSON Schema — nullability is expressed
 *    as a sibling `"nullable": true` boolean alongside a single-string
 *    `"type"`, not JSON Schema's `"type": [T, "null"]` union Claude/
 *    OpenAI's schema-building shares; `additionalProperties`/`required`
 *    keep the same meaning either way. (A Gemini developer forum thread
 *    suggests newer models also now accept the `["T","null"]` union form,
 *    but that's not documented in the primary API reference, so this
 *    class deliberately sticks to the OpenAPI-style `nullable: true` the
 *    reference actually specifies — the safer of the two to depend on.)
 *  - **Web search tool**: `{"googleSearch": {}}` — an always-empty object
 *    value, not a versioned tool name (Claude) or a flat type string
 *    (OpenAI). PHP quirk worth flagging explicitly: an empty PHP array
 *    (`[]`) json_encodes to `[]`, not the required `{}` — see
 *    requestJson() below for why an empty stdClass is used instead of a
 *    plain empty array for this one value.
 *  - **Reasoning effort** is `generationConfig.thinkingConfig.
 *    thinkingLevel` (`"low"`/`"medium"`/`"high"` on this model family —
 *    `"minimal"` exists on other Gemini tiers but 400s specifically on
 *    the model this class uses), the same "medium" positioning Claude's
 *    `effort`/OpenAI's `reasoning.effort` already use for this bounded a
 *    task, just a third distinct field path.
 *  - **Refusal detection is prompt-level, not per-candidate.** A fully
 *    blocked prompt returns a `promptFeedback.blockReason` field and *no*
 *    `candidates` array at all — closer to Claude's top-level
 *    `stop_reason: "refusal"` than to OpenAI's content-item-level
 *    refusal, just a different top-level field name/shape. A safety
 *    block scoped to one candidate instead (rather than the whole
 *    prompt) surfaces as a `candidates[0].finishReason` other than
 *    `"STOP"` with no usable text part — not distinguished from any
 *    other "no text in the response" case below, the same generic
 *    fallback OpenAI's "no message item" edge case already falls into.
 *  - **Model**: `gemini-3.7-flash` — Google's own current model-lineup
 *    documentation places this as the mid-range "Flash" price-performance
 *    tier (a cheaper `-flash-lite` tier and a pricier `-pro` flagship
 *    tier also exist), the same "not the cheapest, not the flagship"
 *    positioning ClaudeMetadataProvider chose Sonnet over Haiku/Opus for,
 *    and OpenAiMetadataProvider chose gpt-5.6-terra over -luna/-sol for.
 *
 * Like ClaudeMetadataProvider/OpenAiMetadataProvider, this was never
 * live-verified against the real Gemini API — this sandbox has network
 * access to generativelanguage.googleapis.com but no credentials to
 * authenticate with. Built and tested only against the documented
 * request/response shapes above (each individually confirmed by fetching
 * Google's own current API reference/guide pages while writing this, not
 * recalled from training data) and hand-built fixtures modeled on them.
 */
trait GeminiMetadataProvider
{
    private const API_URL_TEMPLATE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /** See this trait's own docblock for why gemini-3.7-flash specifically. */
    private const MODEL = 'gemini-3.7-flash';

    /** Same grounding-instruction wording Claude's/OpenAI's DEFAULT_PROMPT use — the instruction itself isn't model-specific, only how it's transmitted (Gemini's separate `systemInstruction` object) is. */
    private const DEFAULT_PROMPT = <<<'PROMPT'
        When looking up this item, prioritize information you can verify from real, publicly accessible sources — online retailers (e.g. JPC, Thalia, Amazon), the publisher's or label's own website, or established databases (e.g. Discogs, IMDb, WorldCat) — over answering purely from your own training knowledge. Use web search to check a real source before answering. If you cannot find reliable information from such a source, say so honestly (set "found" to false, or omit the item) rather than guessing or inventing details.
        PROMPT;

    public function name(): string
    {
        return 'Gemini';
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

    /** Identical shape to ClaudeMetadataProvider::configFields()/OpenAiMetadataProvider::configFields() — same two fields, same reasoning, same AI-Studio-key-only setup the issue's own technical note asked for (no GCP project/service account). */
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
     * @param  array<string, string|array>  $itemFields  Field key => either a bare OpenAPI/Gemini schema type name (e.g. "string", "integer") or a full property schema fragment for anything more than a nullable scalar (e.g. tracks' nested array-of-objects shape).
     */
    protected function lookupSingleItem(string $prompt, array $itemFields): ?array
    {
        $decoded = $this->requestJson($prompt, $this->singleItemSchema($itemFields));

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
        $decoded = $this->requestJson($prompt, $this->itemListSchema($itemFields));
        $items = $decoded['items'] ?? null;

        return is_array($items) ? $items : [];
    }

    /**
     * The actual generateContent round trip — see this trait's own
     * docblock for exactly how this differs from ClaudeMetadataProvider::
     * requestJson()/OpenAiMetadataProvider::requestJson(), point by point.
     */
    private function requestJson(string $userPrompt, array $schema): array
    {
        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            throw new MetadataProviderRequestException('Gemini request failed (missing api_key).');
        }

        $response = Http::withHeaders([
            'x-goog-api-key' => $apiKey,
        ])->timeout(60)->post(sprintf(self::API_URL_TEMPLATE, self::MODEL), [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $userPrompt]]],
            ],
            'systemInstruction' => ['parts' => [['text' => $this->promptPreamble()]]],
            'tools' => [
                // (object) [], not [] — an empty PHP array json_encodes to
                // `[]`, but the API requires an empty JSON *object* here.
                ['googleSearch' => (object) []],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
                // A bounded extraction/lookup task, not open-ended reasoning
                // — see this trait's own docblock on model choice for the
                // same cost-consciousness applied to thinking effort.
                'thinkingConfig' => ['thinkingLevel' => 'medium'],
            ],
        ]);

        if ($response->failed()) {
            throw new MetadataProviderRequestException("Gemini request failed: HTTP {$response->status()}.");
        }

        // A fully blocked prompt returns promptFeedback.blockReason and no
        // candidates at all — Gemini's own equivalent of Claude's top-level
        // stop_reason: "refusal" (see this trait's own docblock).
        if ($response->json('promptFeedback.blockReason') !== null) {
            throw new MetadataProviderRequestException('Gemini declined to answer this request.');
        }

        $textPart = collect($response->json('candidates.0.content.parts', []))
            ->first(fn (array $part) => is_string($part['text'] ?? null) && ! ($part['thought'] ?? false));

        $text = $textPart['text'] ?? null;

        // Also covers a candidate-level (rather than prompt-level) safety
        // block, and any other shape with no usable text part — same
        // generic fallback OpenAI's "no message item" edge case falls into.
        if (! is_string($text)) {
            throw new MetadataProviderRequestException('Gemini response did not include a text block.');
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new MetadataProviderRequestException('Gemini response was not valid JSON.');
        }

        return $decoded;
    }

    /** Same overall shape as ClaudeMetadataProvider::singleItemSchema() — see this trait's own docblock for why fieldSchemas() below builds OpenAPI-style nullable properties instead of Claude/OpenAI's JSON-Schema-style ones. */
    private function singleItemSchema(array $itemFields): array
    {
        ['properties' => $properties, 'required' => $required] = $this->fieldSchemas($itemFields);

        return [
            'type' => 'object',
            'properties' => ['found' => ['type' => 'boolean'], ...$properties],
            'required' => ['found', ...$required],
        ];
    }

    /** Same overall shape as ClaudeMetadataProvider::itemListSchema(). */
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
                    ],
                ],
            ],
            'required' => ['items'],
        ];
    }

    /**
     * Unlike ClaudeMetadataProvider/OpenAiMetadataProvider::fieldSchemas(),
     * a nullable scalar is `{"type": T, "nullable": true}` here, not
     * `{"type": [T, "null"]}` — see this trait's own docblock for why.
     */
    private function fieldSchemas(array $itemFields): array
    {
        $properties = [];
        $required = [];

        foreach ($itemFields as $key => $type) {
            $properties[$key] = is_array($type) ? $type : ['type' => $type, 'nullable' => true];
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
