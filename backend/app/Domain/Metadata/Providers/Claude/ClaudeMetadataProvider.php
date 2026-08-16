<?php

namespace App\Domain\Metadata\Providers\Claude;

use App\Domain\Metadata\Contracts\MetadataProviderConfigField;
use App\Domain\Metadata\MetadataProviderRequestException;
use App\Models\MetadataPlugin;
use Illuminate\Support\Facades\Http;

/**
 * Shared logic for the three Claude-backed metadata providers (book/cd/
 * dvd_bluray, GitHub issue #59) — the LLM-as-metadata-source concept the
 * issue asked about, using Claude specifically since that's what this app
 * was itself built with. Structural mirror of AmazonScraping.php (one
 * trait shared by three per-media-type classes, briefing 8.1's plugin
 * interface implemented identically by each) and, like the Amazon
 * providers, disabled by default and marked Beta — see
 * MetadataProviderRegistry::DEFAULT_DISABLED_PROVIDER_KEYS's docblock for
 * why, and this class's own for the reasoning specific to an LLM source.
 *
 * The issue's own risk assessment is taken at face value here, not
 * dismissed:
 *  - **No real cover image.** A plain Messages API call cannot return a
 *    genuine, existing image URL — every concrete provider below always
 *    returns an empty `coverUrls`, never a guessed one (a fabricated image
 *    URL is worse than none: it fails silently as a broken thumbnail
 *    instead of being visibly absent).
 *  - **No price/currency.** Marketplace prices are volatile, not a fixed
 *    fact a model can "know" the way a title or ISBN is, and the issue's
 *    own hallucination-risk example ("exact ISBN") applies at least as
 *    much to a specific number like a price — so `price`/`currency` are
 *    deliberately left out of every schema below, never asked for.
 *  - **Hallucination**, the issue's central concern, is addressed the way
 *    its own addendum asks: `web_search_20260209` (briefing: none —
 *    server-side, no client round-trip needed) is declared as a tool on
 *    every request alongside the admin-editable grounding prompt
 *    (`configFields()`'s `prompt` field), so the model can actually look
 *    at a real retailer/database page instead of only being told to
 *    pretend it did. This doesn't eliminate the risk the issue names —
 *    only real-source grounding meaningfully reduces it, and even that is
 *    not a guarantee — which is exactly why this stays Beta/opt-in.
 *
 * `output_config.format` (structured outputs, not assistant-turn prefill —
 * prefill 400s on Claude Sonnet 5) constrains the final answer to a strict
 * JSON schema per concrete provider's own field set, so no per-provider
 * free-text parsing is needed; `found`/`items` fields let the model
 * represent "no confident match" explicitly rather than being forced to
 * invent something to satisfy the schema. Model choice
 * (self::MODEL = claude-sonnet-5, not the pricier claude-opus-5): this is
 * a bounded extraction/lookup task, not open-ended reasoning, and the
 * issue itself names per-call cost as a real, first-class concern for an
 * admin who enables this — Sonnet is Anthropic's own recommended tier for
 * "classification, summarization, extraction, Q&A" work like this.
 *
 * Unlike Discogs/Hardcover/UpcMdbProvider, this was never live-verified
 * against the real Anthropic API — this sandbox has network access to
 * api.anthropic.com but no credentials to authenticate with, and asking
 * for the user's own production API key to test a self-hosted app feature
 * isn't appropriate. Built and tested only against the documented request/
 * response shapes (Anthropic's Messages API, structured outputs, and the
 * web_search_20260209 server tool) and hand-built fixtures modeled on
 * them — same disclosed-limitation precedent as AmazonScraping's own
 * docblock for why that one was never live-verified either, for different
 * reasons (there, live-testing itself would have been the very scraping
 * traffic the feature is risky about).
 */
trait ClaudeMetadataProvider
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    /** See this trait's own docblock for why Sonnet, not Opus. */
    private const MODEL = 'claude-sonnet-5';

    /**
     * Admin-editable default for the `prompt` config field (GitHub issue
     * #59's addendum) — instructs the model to ground its answer in real,
     * verifiable sources rather than answering purely from its own
     * training knowledge, directly addressing the issue's hallucination
     * concern. Deliberately names concrete retailers/databases (the same
     * examples the addendum itself gives) rather than a vague "use
     * reliable sources", since a specific instruction is far more likely
     * to actually steer the model towards using the web_search tool this
     * class also declares (see this trait's own docblock).
     */
    private const DEFAULT_PROMPT = <<<'PROMPT'
        When looking up this item, prioritize information you can verify from real, publicly accessible sources — online retailers (e.g. JPC, Thalia, Amazon), the publisher's or label's own website, or established databases (e.g. Discogs, IMDb, WorldCat) — over answering purely from your own training knowledge. Use web search to check a real source before answering. If you cannot find reliable information from such a source, say so honestly (set "found" to false, or omit the item) rather than guessing or inventing details.
        PROMPT;

    public function name(): string
    {
        return 'Claude';
    }

    /**
     * "v0.1-beta" (GitHub issue #44's free-form version string, same
     * "-beta"-suffixed pattern the Amazon scrapers use for #50) — the
     * suffix alone conveys the beta status (GitHub issue #52's
     * simplification: no redundant "(Beta)" text in name() as well).
     */
    public function version(): string
    {
        return 'v0.1-beta';
    }

    /**
     * A third source-type category beyond briefing 15./GitHub issue #55's
     * original 'api'|'scraping' pair, exactly as issue #59 itself proposed
     * — an LLM-backed source is neither a documented third-party API nor a
     * scrape of a page not meant to be machine-read; it carries its own,
     * different risk profile (see this trait's own docblock).
     */
    public function sourceType(): string
    {
        return 'llm';
    }

    /**
     * `api_key` (required, matching every other API-key-gated provider's
     * shape, e.g. UpcMdbProvider) plus `prompt` (GitHub issue #59's
     * addendum, "variant 1" from its own technical note: a new `textarea`
     * config-field type — see MetadataProviderConfigField's docblock —
     * pre-filled with a sensible default rather than starting empty).
     */
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
     * confident match (`found: false`) — distinguished from a request
     * that failed outright (MetadataProviderRequestException, thrown by
     * requestJson() below).
     *
     * @param  array<string, string|array>  $itemFields  Field key => either a bare JSON Schema type name (e.g. "string", "integer") or a full property schema fragment for anything more than a nullable scalar (e.g. tracks' nested array-of-objects shape).
     */
    protected function lookupSingleItem(string $prompt, array $itemFields): ?array
    {
        $decoded = $this->requestJson($prompt, $this->singleItemSchema($itemFields));

        return ($decoded['found'] ?? false) ? $decoded : null;
    }

    /**
     * Runs one grounded free-text search and returns the decoded list of
     * item objects (possibly empty — never throws just because nothing
     * confident was found, same as every other provider's search()).
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
     * The actual Messages API round trip: structured outputs
     * (`output_config.format`) constrain the response to $schema, and the
     * web_search_20260209 server tool (see this trait's own docblock) lets
     * the model ground its answer in a real page within this same request
     * — no client-side tool-result round trip needed, since web search
     * executes server-side.
     */
    private function requestJson(string $userPrompt, array $schema): array
    {
        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            throw new MetadataProviderRequestException('Claude request failed (missing api_key).');
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])->timeout(60)->post(self::API_URL, [
            'model' => self::MODEL,
            'max_tokens' => 8192,
            'system' => $this->promptPreamble(),
            'tools' => [
                ['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 5],
            ],
            'output_config' => [
                'format' => ['type' => 'json_schema', 'schema' => $schema],
                // A bounded extraction/lookup task, not open-ended
                // reasoning — see this trait's own docblock on model choice
                // for the same cost-consciousness applied to effort.
                'effort' => 'medium',
            ],
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ]);

        if ($response->failed()) {
            throw new MetadataProviderRequestException("Claude request failed: HTTP {$response->status()}.");
        }

        // A declined request (safety classifiers) is a normal HTTP 200 with
        // stop_reason: "refusal", not a non-2xx status — must be checked
        // explicitly, see the claude-api skill's refusal-handling guidance.
        if ($response->json('stop_reason') === 'refusal') {
            throw new MetadataProviderRequestException('Claude declined to answer this request.');
        }

        $text = collect($response->json('content', []))
            ->firstWhere('type', 'text')['text'] ?? null;

        if (! is_string($text)) {
            throw new MetadataProviderRequestException('Claude response did not include a text block.');
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new MetadataProviderRequestException('Claude response was not valid JSON.');
        }

        return $decoded;
    }

    /** @param  array<string, string|array>  $itemFields */
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

    /** @param  array<string, string|array>  $itemFields */
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

    /** @param  array<string, string|array>  $itemFields */
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

    /** Falls back to DEFAULT_PROMPT when an admin enabled this plugin without ever opening its settings dialog — the pre-filled default (configFields() above) only reaches metadata_plugins.config once actually saved. */
    private function promptPreamble(): string
    {
        $prompt = $this->config()['prompt'] ?? null;

        return is_string($prompt) && trim($prompt) !== '' ? $prompt : self::DEFAULT_PROMPT;
    }

    /** Same runtime-configured-secret pattern as UpcMdbProvider::apiKey()/HardcoverProvider::apiKey(). */
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
