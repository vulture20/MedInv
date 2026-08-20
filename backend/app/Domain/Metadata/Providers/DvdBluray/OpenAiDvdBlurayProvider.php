<?php

namespace App\Domain\Metadata\Providers\DvdBluray;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\Providers\OpenAi\OpenAiMetadataProvider;

/**
 * OpenAI-backed DVD/Blu-ray metadata plugin (GitHub issue #65) — see
 * OpenAiMetadataProvider's docblock for the shared reasoning and exactly
 * how it differs from ClaudeDvdBlurayProvider's own identical-shaped
 * implementation.
 */
class OpenAiDvdBlurayProvider implements MetadataProviderInterface
{
    use OpenAiMetadataProvider;

    /** Identical to ClaudeDvdBlurayProvider::ITEM_FIELDS (including GitHub issue #140's `genre`/`subtitles`) — see its own docblock. */
    private const ITEM_FIELDS = [
        'title' => 'string',
        'description' => 'string',
        'medium' => 'string',
        'disc_count' => 'integer',
        'runtime_minutes' => 'integer',
        'languages' => 'string',
        'subtitles' => 'string',
        'cast' => 'string',
        'director' => 'string',
        'genre' => 'string',
        'release_date' => 'string',
        'production_year' => 'integer',
    ];

    public function key(): string
    {
        return 'dvd_bluray.openai';
    }

    public function mediaType(): string
    {
        return 'dvd_bluray';
    }

    public function lookupByCode(string $code): array
    {
        $item = $this->lookupSingleItem(
            "Find the exact DVD or Blu-ray release whose EAN/UPC barcode is \"{$code}\". Confirm the barcode actually matches before answering.",
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
            "Search for real, released DVDs/Blu-rays matching: \"{$query}\". Return up to 5 distinct results, most relevant first. Do not invent releases that don't exist.",
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
                'description' => $item['description'] ?? null,
                'medium' => $item['medium'] ?? null,
                'disc_count' => $item['disc_count'] ?? null,
                'runtime_minutes' => $item['runtime_minutes'] ?? null,
                'languages' => $item['languages'] ?? null,
                'subtitles' => $item['subtitles'] ?? null,
                'cast' => $item['cast'] ?? null,
                'director' => $item['director'] ?? null,
                'genre' => $item['genre'] ?? null,
                'release_date' => $item['release_date'] ?? null,
                'production_year' => $item['production_year'] ?? null,
                'ean' => $ean,
            ],
            // Never a real cover URL — see OpenAiMetadataProvider's docblock.
            coverUrls: [],
        );
    }
}
