<?php

namespace App\Domain\Metadata\Providers\Cd;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\Providers\Gemini\GeminiMetadataProvider;

/**
 * Gemini-backed CD metadata plugin (GitHub issue #66) — see
 * GeminiMetadataProvider's docblock for the shared reasoning (grounding
 * via Google Search, no cover/price fields, model/effort choice, Beta/
 * opt-in status, never live-verified).
 */
class GeminiCdProvider implements MetadataProviderInterface
{
    use GeminiMetadataProvider;

    /**
     * Same field set as ClaudeCdProvider::ITEM_FIELDS/OpenAiCdProvider::
     * ITEM_FIELDS (see ClaudeCdProvider's own docblock for why
     * runtime_seconds/runtime_computed are omitted), just expressed in
     * GeminiMetadataProvider::fieldSchemas()'s OpenAPI-style nullable
     * shape (`nullable: true` alongside a single `type`) instead of
     * Claude/OpenAI's JSON-Schema-style `type: [T, "null"]` union — see
     * GeminiMetadataProvider's own docblock for why.
     */
    private const ITEM_FIELDS = [
        'title' => 'string',
        'artist' => 'string',
        'description' => 'string',
        'medium' => 'string',
        'disc_count' => 'integer',
        'release_date' => 'string',
        'tracks' => [
            'type' => 'array',
            'nullable' => true,
            'items' => [
                'type' => 'object',
                'properties' => [
                    'position' => ['type' => 'string', 'nullable' => true],
                    'title' => ['type' => 'string', 'nullable' => true],
                    'duration_seconds' => ['type' => 'integer', 'nullable' => true],
                ],
                'required' => ['position', 'title', 'duration_seconds'],
            ],
        ],
    ];

    public function key(): string
    {
        return 'cd.gemini';
    }

    public function mediaType(): string
    {
        return 'cd';
    }

    public function lookupByCode(string $code): array
    {
        $item = $this->lookupSingleItem(
            "Find the exact CD/music release whose EAN/UPC barcode is \"{$code}\". Confirm the barcode actually matches before answering.",
            self::ITEM_FIELDS,
        );

        if ($item === null) {
            return [];
        }

        return [$this->mapToCandidate($item, $code)];
    }

    public function search(string $query): array
    {
        $items = $this->searchItems(
            "Search for real, released CDs/music albums matching: \"{$query}\". Return up to 5 distinct results, most relevant first. Do not invent releases that don't exist.",
            self::ITEM_FIELDS,
        );

        return collect($items)
            ->map(fn (array $item) => $this->mapToCandidate($item, null))
            ->all();
    }

    private function mapToCandidate(array $item, ?string $ean): MetadataCandidate
    {
        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: $ean ?? ($item['title'] ?? ''),
            attributes: [
                'title' => $item['title'] ?? null,
                'artist' => $item['artist'] ?? null,
                'description' => $item['description'] ?? null,
                'medium' => $item['medium'] ?? null,
                'disc_count' => $item['disc_count'] ?? null,
                'release_date' => $item['release_date'] ?? null,
                'tracks' => $item['tracks'] ?? null,
                'ean' => $ean,
            ],
            // Never a real cover URL — see GeminiMetadataProvider's docblock.
            coverUrls: [],
        );
    }
}
