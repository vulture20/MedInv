<?php

namespace App\Domain\Metadata\Providers\Cd;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\Providers\OpenAi\OpenAiMetadataProvider;

/**
 * OpenAI-backed CD metadata plugin (GitHub issue #65) — see
 * OpenAiMetadataProvider's docblock for the shared reasoning and exactly
 * how it differs from ClaudeCdProvider's own identical-shaped
 * implementation.
 */
class OpenAiCdProvider implements MetadataProviderInterface
{
    use OpenAiMetadataProvider;

    /** Identical to ClaudeCdProvider::ITEM_FIELDS — see its own docblock (no runtime_seconds/runtime_computed, derived from `tracks` instead). */
    private const ITEM_FIELDS = [
        'title' => 'string',
        'artist' => 'string',
        'description' => 'string',
        'medium' => 'string',
        'disc_count' => 'integer',
        'release_date' => 'string',
        'tracks' => [
            'type' => ['array', 'null'],
            'items' => [
                'type' => 'object',
                'properties' => [
                    'position' => ['type' => ['string', 'null']],
                    'title' => ['type' => ['string', 'null']],
                    'duration_seconds' => ['type' => ['integer', 'null']],
                ],
                'required' => ['position', 'title', 'duration_seconds'],
                'additionalProperties' => false,
            ],
        ],
    ];

    public function key(): string
    {
        return 'cd.openai';
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
            // Never a real cover URL — see OpenAiMetadataProvider's docblock.
            coverUrls: [],
        );
    }
}
