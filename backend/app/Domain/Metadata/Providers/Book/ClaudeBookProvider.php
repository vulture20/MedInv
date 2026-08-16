<?php

namespace App\Domain\Metadata\Providers\Book;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\Providers\Claude\ClaudeMetadataProvider;

/**
 * Claude-backed book metadata plugin (GitHub issue #59) — see
 * ClaudeMetadataProvider's docblock for the shared reasoning (grounding
 * via web search, no cover/price fields, model/effort choice, Beta/
 * opt-in status, never live-verified).
 */
class ClaudeBookProvider implements MetadataProviderInterface
{
    use ClaudeMetadataProvider;

    /** MediaBook's fillable fields this provider can plausibly know, minus library_id/cover_path (structural) and price/currency (see ClaudeMetadataProvider's docblock). */
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
        return 'book.claude';
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
            // Never a real cover URL — see ClaudeMetadataProvider's docblock.
            coverUrls: [],
        );
    }
}
