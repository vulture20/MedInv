<?php

namespace App\Domain\Metadata\Providers\DvdBluray;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\Providers\Claude\ClaudeMetadataProvider;

/**
 * Claude-backed DVD/Blu-ray metadata plugin (GitHub issue #59) — see
 * ClaudeMetadataProvider's docblock for the shared reasoning (grounding
 * via web search, no cover/price fields, model/effort choice, Beta/
 * opt-in status, never live-verified).
 */
class ClaudeDvdBlurayProvider implements MetadataProviderInterface
{
    use ClaudeMetadataProvider;

    /**
     * MediaDvdBluray's fillable fields this provider can plausibly know,
     * minus library_id/cover_path (structural) and price/currency (see
     * ClaudeMetadataProvider's docblock). `languages` is a plain
     * free-text column (see the media_dvd_blurays migration), not a JSON
     * array — same shape UpcMdbProvider/AmazonDvdBlurayProvider already
     * produce, e.g. "English, German". `genre`/`subtitles` (GitHub issue
     * #140) follow that same free-text shape.
     */
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
        return 'dvd_bluray.claude';
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
            // Never a real cover URL — see ClaudeMetadataProvider's docblock.
            coverUrls: [],
        );
    }
}
