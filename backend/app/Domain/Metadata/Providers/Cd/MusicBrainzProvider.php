<?php

namespace App\Domain\Metadata\Providers\Cd;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use Illuminate\Support\Facades\Http;

/**
 * CD metadata plugin using the free MusicBrainz API (briefing 8.2 — CD).
 * Amazon and Discogs, the other two listed CD sources, follow the same
 * shape under this namespace.
 *
 * The base URL is `/ws/2/release` (a slash between `ws` and `2`) — GitHub
 * issue #48's investigation found this had been `/ws2/release` (no slash)
 * ever since this provider was written, live-confirmed to 404 on every
 * single request. lookupByCode()/search() treat a failed response as
 * simply "no results" rather than an error, so this provider silently
 * returned zero candidates for every CD lookup, indefinitely, with nothing
 * in the log to suggest why — MusicBrainz itself was never actually
 * queried.
 *
 * Track listings (GitHub issue #48) are *not* part of a plain search
 * response — confirmed live: even with `inc=recordings` on the search
 * endpoint, `releases[].media` only ever carries a bare `track-count`, not
 * the actual titles/durations. Those only come back from a *direct* lookup
 * of one specific release by its MBID with `inc=recordings` (`media[].
 * tracks[].title`/`.length` in milliseconds) — the same two-call shape
 * DiscogsProvider already uses for its own cover/tracklist data. That
 * second call is deliberately capped to MAX_RELEASES_WITH_TRACKS_FETCHED
 * releases per lookupByCode() call: MusicBrainz's unauthenticated rate
 * limit is a strict 1 request/second (far tighter than Discogs' 25/min),
 * and a popular barcode's search can return upwards of ten releases.
 */
class MusicBrainzProvider implements MetadataProviderInterface
{
    /** See this class's docblock for why this is capped at all. */
    private const MAX_RELEASES_WITH_TRACKS_FETCHED = 3;

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

    /** MusicBrainz's web service is free and unauthenticated — nothing to configure. */
    public function configFields(): array
    {
        return [];
    }

    /** See MetadataProviderInterface::version()'s docblock (GitHub issue #44). */
    public function version(): string
    {
        return 'v1.0';
    }

    public function lookupByCode(string $code): array
    {
        $response = Http::withHeaders(['User-Agent' => 'MedInv/1.0'])
            ->get('https://musicbrainz.org/ws/2/release', ['query' => "barcode:{$code}", 'fmt' => 'json']);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json('releases', []))
            ->map(function (array $release, int $index) use ($code) {
                $tracks = $index < self::MAX_RELEASES_WITH_TRACKS_FETCHED && isset($release['id'])
                    ? $this->fetchTracks($release['id'])
                    : null;

                return $this->mapToCandidate($release, $code, $tracks);
            })
            ->all();
    }

    public function search(string $query): array
    {
        $response = Http::withHeaders(['User-Agent' => 'MedInv/1.0'])
            ->get('https://musicbrainz.org/ws/2/release', ['query' => $query, 'fmt' => 'json']);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json('releases', []))
            ->map(fn (array $release) => $this->mapToCandidate($release, null, null))
            ->all();
    }

    /** @param  array<int, array{position: string|null, title: string|null, duration_seconds: int|null}>|null  $tracks  Null when not fetched at all (see MAX_RELEASES_WITH_TRACKS_FETCHED) — distinct from an empty array (fetched, but the release genuinely has none/unusable data). */
    private function mapToCandidate(array $release, ?string $ean, ?array $tracks): MetadataCandidate
    {
        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: $release['id'] ?? '',
            attributes: [
                'title' => $release['title'] ?? null,
                'artist' => collect($release['artist-credit'] ?? [])->pluck('name')->implode(', '),
                'release_date' => $release['date'] ?? null,
                'ean' => $ean ?? $release['barcode'] ?? null,
                // No 'runtime_seconds'/'runtime_computed' here on purpose —
                // see DiscogsProvider::mapReleaseToCandidate()'s matching
                // comment: a runtime can only be *derived* from whichever
                // `tracks` value is ultimately chosen, never set
                // independently of it.
                'tracks' => $tracks,
            ],
        );
    }

    /**
     * Direct lookup of one release by MBID with `inc=recordings` — the only
     * MusicBrainz call that actually returns track titles/durations, see
     * this class's docblock. Best-effort: a failure here must not fail the
     * whole candidate, the same trade-off DiscogsProvider's own extra
     * detail fetch makes.
     *
     * @return array<int, array{position: string|null, title: string|null, duration_seconds: int|null}>
     */
    private function fetchTracks(string $releaseId): array
    {
        $response = Http::withHeaders(['User-Agent' => 'MedInv/1.0'])
            ->get("https://musicbrainz.org/ws/2/release/{$releaseId}", ['fmt' => 'json', 'inc' => 'recordings']);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json('media', []))
            ->flatMap(fn (array $medium) => $medium['tracks'] ?? [])
            ->map(fn (array $track) => [
                'position' => isset($track['number']) ? (string) $track['number'] : null,
                'title' => $track['title'] ?? null,
                'duration_seconds' => isset($track['length']) ? intdiv((int) $track['length'], 1000) : null,
            ])
            ->values()
            ->all();
    }
}
