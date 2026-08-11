<?php

namespace App\Domain\Metadata\Providers\Cd;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use Illuminate\Support\Facades\Http;

/**
 * CD metadata plugin using the free MusicBrainz API (briefing 8.2 — CD).
 * Amazon and Discogs, the other two listed CD sources, follow the same
 * shape under this namespace.
 */
class MusicBrainzProvider implements MetadataProviderInterface
{
    public function key(): string
    {
        return 'cd.musicbrainz';
    }

    public function name(): string
    {
        return 'MusicBrainz';
    }

    public function mediaType(): string
    {
        return 'cd';
    }

    public function lookupByCode(string $code): array
    {
        $response = Http::withHeaders(['User-Agent' => 'MedInv/1.0'])
            ->get('https://musicbrainz.org/ws2/release', ['query' => "barcode:{$code}", 'fmt' => 'json']);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json('releases', []))
            ->map(fn (array $release) => $this->mapToCandidate($release, $code))
            ->all();
    }

    public function search(string $query): array
    {
        $response = Http::withHeaders(['User-Agent' => 'MedInv/1.0'])
            ->get('https://musicbrainz.org/ws2/release', ['query' => $query, 'fmt' => 'json']);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json('releases', []))
            ->map(fn (array $release) => $this->mapToCandidate($release, null))
            ->all();
    }

    private function mapToCandidate(array $release, ?string $ean): MetadataCandidate
    {
        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: $release['id'] ?? '',
            attributes: [
                'title' => $release['title'] ?? null,
                'artist' => collect($release['artist-credit'] ?? [])->pluck('name')->implode(', '),
                'release_date' => $release['date'] ?? null,
                'ean' => $ean ?? $release['barcode'] ?? null,
            ],
        );
    }
}
