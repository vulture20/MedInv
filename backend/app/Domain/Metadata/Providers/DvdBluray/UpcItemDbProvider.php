<?php

namespace App\Domain\Metadata\Providers\DvdBluray;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use Illuminate\Support\Facades\Http;

/**
 * DVD/Blu-ray metadata plugin using the free-tier UPCitemdb API (briefing
 * 8.2 — DVD/Blu-ray). Amazon and Emunation.ch, the other two listed
 * sources, follow the same shape under this namespace.
 */
class UpcItemDbProvider implements MetadataProviderInterface
{
    public function key(): string
    {
        return 'dvd_bluray.upcitemdb';
    }

    public function name(): string
    {
        return 'UPCitemdb';
    }

    public function mediaType(): string
    {
        return 'dvd_bluray';
    }

    public function lookupByCode(string $code): array
    {
        $response = Http::get('https://api.upcitemdb.com/prod/trial/lookup', ['upc' => $code]);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json('items', []))
            ->map(fn (array $item) => $this->mapToCandidate($item, $code))
            ->all();
    }

    public function search(string $query): array
    {
        $response = Http::get('https://api.upcitemdb.com/prod/trial/search', ['s' => $query]);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json('items', []))
            ->map(fn (array $item) => $this->mapToCandidate($item, $item['upc'] ?? null))
            ->all();
    }

    private function mapToCandidate(array $item, ?string $ean): MetadataCandidate
    {
        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: $item['upc'] ?? ($ean ?? ''),
            attributes: [
                'title' => $item['title'] ?? null,
                'description' => $item['description'] ?? null,
                'release_date' => $item['release_date'] ?? null,
                'ean' => $ean ?? $item['upc'] ?? null,
            ],
            coverUrls: $item['images'] ?? [],
        );
    }
}
