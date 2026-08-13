<?php

namespace App\Domain\Metadata\Providers\Cd;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderConfigField;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Models\MetadataPlugin;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Discogs metadata plugin (briefing 8.2 — CD, GitHub issue #22). Discogs'
 * REST API (https://api.discogs.com) is free and works fully unauthenticated
 * — confirmed live (a real barcode search and release lookup both
 * succeeded with no token at all) — unlike Hardcover's or UPCMDB's APIs.
 * `configFields()` therefore declares an *optional* `api_key` (a personal
 * access token from discogs.com/settings/developers), the same shape as
 * GoogleBooksProvider's: unauthenticated requests are rate-limited to
 * 25/min (confirmed live via the `x-discogs-ratelimit` response header),
 * an authenticated request raises that to 60/min. Sent as
 * `Authorization: Discogs token={key}`, Discogs' own long-documented,
 * stable convention (this specific header could not itself be
 * live-verified — that requires a real personal token, which wasn't
 * available — but the request degrades gracefully to the
 * already-verified unauthenticated path if it's ever wrong).
 *
 * lookupByCode() searches by barcode, then fetches the full release
 * (a second call, GET /releases/{id}) for the matched result — confirmed
 * live that the search endpoint's own summary objects never carry a
 * `notes`/description field and always report an empty `cover_image`/
 * `thumb` (Discogs appears to omit image data from search results
 * entirely for unauthenticated requests), while the full release record
 * has both, plus the precise ISO `released` date instead of just a bare
 * `year`. Same two-call shape as OpenLibraryProvider's Books-API +
 * Editions-API split (issue #28). search() deliberately stays
 * single-call — enriching up to 10 results would burn through nearly half
 * of Discogs' already-tight unauthenticated per-minute quota in one
 * search, the same reasoning GoogleBooksProvider's search() already
 * follows.
 */
class DiscogsProvider implements MetadataProviderInterface
{
    private const BASE_URL = 'https://api.discogs.com';

    private const USER_AGENT = 'MedInv (https://github.com/vulture20/MedInv)';

    public function key(): string
    {
        return 'cd.discogs';
    }

    public function name(): string
    {
        return 'Discogs';
    }

    public function mediaType(): string
    {
        return 'cd';
    }

    public function configFields(): array
    {
        return [
            new MetadataProviderConfigField('api_key', type: 'password', required: false),
        ];
    }

    public function lookupByCode(string $code): array
    {
        $response = $this->request('/database/search', ['barcode' => $code, 'type' => 'release']);

        if ($response === null) {
            return [];
        }

        $result = $response->json('results.0');

        if (! $result) {
            return [];
        }

        $release = isset($result['id']) ? $this->fetchRelease($result['id']) : null;

        return [$release !== null
            ? $this->mapReleaseToCandidate($release, $code)
            : $this->mapSearchResultToCandidate($result, $code)];
    }

    public function search(string $query): array
    {
        $response = $this->request('/database/search', ['q' => $query, 'type' => 'release']);

        if ($response === null) {
            return [];
        }

        return collect($response->json('results', []))
            ->map(fn (array $result) => $this->mapSearchResultToCandidate($result, null))
            ->all();
    }

    /** The full release record (GET /releases/{id}) — see this class's docblock for why lookupByCode() fetches it in addition to the search hit. */
    private function fetchRelease(int $id): ?array
    {
        $response = $this->request("/releases/{$id}", []);

        return $response?->json();
    }

    private function mapReleaseToCandidate(array $release, string $code): MetadataCandidate
    {
        $format = $release['formats'][0] ?? [];

        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: (string) ($release['id'] ?? $code),
            attributes: [
                'title' => $release['title'] ?? null,
                'artist' => $release['artists_sort'] ?? null,
                'description' => $release['notes'] ?? null,
                'medium' => $format['name'] ?? null,
                'disc_count' => isset($format['qty']) ? (int) $format['qty'] : null,
                'release_date' => $release['released'] ?? null,
                'ean' => $code,
            ],
            coverUrls: collect($release['images'] ?? [])->pluck('uri')->filter()->values()->all(),
        );
    }

    /**
     * Fallback shape used by search() (which only ever sees this flatter
     * summary object) and by lookupByCode() when the full-release fetch
     * fails. Unlike the full release record, this never has a cover
     * (`cover_image`/`thumb` come back empty for unauthenticated requests,
     * confirmed live) or a description, and `title` is Discogs' own
     * combined "{artist} - {release title}" string rather than separate
     * fields, so it's split on the first " - " the same way Discogs
     * itself constructs it.
     */
    private function mapSearchResultToCandidate(array $result, ?string $code): MetadataCandidate
    {
        [$artist, $title] = $this->splitArtistTitle($result['title'] ?? '');

        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: (string) ($result['id'] ?? $code ?? ''),
            attributes: [
                'title' => $title,
                'artist' => $artist,
                'medium' => $result['format'][0] ?? null,
                'release_date' => isset($result['year']) ? "{$result['year']}-01-01" : null,
                'ean' => $code,
            ],
        );
    }

    /** @return array{0: ?string, 1: ?string} [artist, title] */
    private function splitArtistTitle(string $combined): array
    {
        if (str_contains($combined, ' - ')) {
            [$artist, $title] = explode(' - ', $combined, 2);

            return [$artist, $title];
        }

        return [null, $combined ?: null];
    }

    private function request(string $path, array $query): ?Response
    {
        $headers = ['User-Agent' => self::USER_AGENT];

        if ($apiKey = $this->apiKey()) {
            $headers['Authorization'] = "Discogs token={$apiKey}";
        }

        $response = Http::withHeaders($headers)->get(self::BASE_URL.$path, $query);

        return $response->successful() ? $response : null;
    }

    /** Same runtime-configured-secret pattern as UpcMdbProvider::apiKey() — see that class's docblock. Optional here, unlike UpcMdbProvider's required key — see this class's docblock. */
    private function apiKey(): ?string
    {
        $config = MetadataPlugin::query()->where('provider_key', $this->key())->first()?->config;

        return $config['api_key'] ?? null;
    }
}
