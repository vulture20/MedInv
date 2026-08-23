<?php

namespace App\Domain\Metadata\Providers\Book;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\Providers\Mistral\MistralMetadataProvider;

/**
 * Mistral-backed book metadata plugin (GitHub issue #68) — see
 * MistralMetadataProvider's docblock for the shared reasoning (grounding
 * via web search on the Conversations API, no cover/price fields, model
 * choice, Beta/opt-in status, never live-verified, and the one disclosed
 * unconfirmed assumption about response_format + web_search).
 */
class MistralBookProvider implements MetadataProviderInterface
{
    use MistralMetadataProvider;

    /** Identical to ClaudeBookProvider::ITEM_FIELDS — see its own docblock. */
    private const ITEM_FIELDS = [
        'title' => 'string',
        'authors' => 'string',
        'description' => 'string',
        'genre' => 'string',
        'publisher' => 'string',
        'page_count' => 'integer',
        'language' => 'string',
        'release_date' => 'string',
        'format' => 'string',
        'isbn10' => 'string',
        'isbn13' => 'string',
    ];

    public function key(): string
    {
        return 'book.mistral';
    }

    public function mediaType(): string
    {
        return 'book';
    }

    public function lookupByCode(string $code): array
    {
        $item = $this->lookupSingleItem(
            "Find the exact book whose ISBN or EAN barcode is \"{$code}\". Confirm the ISBN/EAN actually matches before answering.",
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
            "Search for real, published books matching: \"{$query}\". Return up to 5 distinct results, most relevant first. Do not invent books that don't exist.",
            self::ITEM_FIELDS,
        );

        return collect($items)
            ->map(fn (array $item) => $this->mapToCandidate($item, $item['isbn13'] ?? $item['isbn10'] ?? $item['title'] ?? ''))
            ->all();
    }

    private function mapToCandidate(array $item, string $sourceId): MetadataCandidate
    {
        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: $sourceId,
            attributes: [
                'title' => $item['title'] ?? null,
                'authors' => $item['authors'] ?? null,
                'description' => $item['description'] ?? null,
                'genre' => $item['genre'] ?? null,
                'publisher' => $item['publisher'] ?? null,
                'page_count' => $item['page_count'] ?? null,
                'language' => $item['language'] ?? null,
                'release_date' => $item['release_date'] ?? null,
                'format' => $item['format'] ?? null,
                'isbn10' => $item['isbn10'] ?? null,
                'isbn13' => $item['isbn13'] ?? null,
            ],
            // Never a real cover URL — see MistralMetadataProvider's docblock.
            coverUrls: [],
        );
    }
}
