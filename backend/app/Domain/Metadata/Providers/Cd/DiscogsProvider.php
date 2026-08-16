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
 * `thumb` for an unauthenticated request (an authenticated one does get
 * real values there too, confirmed live against a real personal token —
 * but this class never uses that field either way, see below), while the
 * full release record's `images` array is populated regardless of
 * authentication. Same two-call shape as OpenLibraryProvider's Books-API +
 * Editions-API split (issue #28). search() deliberately stays
 * single-call — enriching up to 10 results would burn through nearly half
 * of Discogs' already-tight unauthenticated per-minute quota in one
 * search, the same reasoning GoogleBooksProvider's search() already
 * follows.
 *
 * A cover URL extracted correctly here can still fail to actually become
 * a stored cover: Discogs' image CDN (i.discogs.com) blocks the request
 * CoverDownloadService makes to download it (a Cloudflare-level client
 * fingerprint issue, unrelated to this class or to the URL itself — see
 * CurlImageFetcher's docblock for the fix). A real cover-import bug
 * report turned out to be that, not a wrong URL from here.
 *
 * A second, distinct cover-loss cause, also confirmed live (barcode
 * 039841615609, "Igorrr - Amen"): a single barcode can match *multiple*
 * releases — Discogs allows independent listings (regional/unofficial
 * pressings, bootlegs, etc.) to carry the same barcode — and the search
 * endpoint's own ranking is not "the one with a cover art first"; for
 * this barcode the top hit was an "Unofficial Release" release with a
 * completely empty `images` array on its full release record, while the
 * very next result was the official release with a proper cover.
 * lookupByCode() used to fetch only `results[0]`'s full release
 * unconditionally, so it silently produced a cover-less candidate despite
 * a perfectly good cover being one search result away.
 * fetchReleaseWithCover() now checks a handful of the barcode's search
 * results (not just the first) and returns the first one whose full
 * release record actually has images, falling back to the very first
 * result — same as the old unconditional behavior — only if none of the
 * checked results have one.
 *
 * `released` (the full release record's date field) is free-form and
 * inconsistently populated — confirmed live across real releases: a full
 * ISO date ("1997-07-01"), a bare year ("1974"), a year with an unknown
 * month/day ("1980-01-00"), or absent entirely. Storing any of the
 * non-ISO shapes directly into a `date`-cast column produced a wrong or
 * silently-mangled date (a real bug report: "the release year wasn't
 * imported correctly") — normalizeReleaseDate() normalizes all of these
 * into a real date string or null instead of assuming the field is
 * always already a clean ISO date.
 */
class DiscogsProvider implements MetadataProviderInterface
{
    private const BASE_URL = 'https://api.discogs.com';

    private const USER_AGENT = 'MedInv (https://github.com/vulture20/MedInv)';

    /**
     * How many of a barcode's search results fetchReleaseWithCover() will
     * fetch the full release record for while looking for one with a
     * cover — a barcode reused across many pressings could otherwise burn
     * through a large chunk of Discogs' unauthenticated 25/min quota for a
     * single capture. lookupByCode() is a single-item lookup (the user
     * just scanned/typed one EAN), unlike search(), which deliberately
     * stays single-call for the same quota reason across up to 10 results.
     */
    private const MAX_BARCODE_MATCHES_CHECKED = 5;

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

    /** See MetadataProviderInterface::version()'s docblock (GitHub issue #44). */
    public function version(): string
    {
        return 'v1.0';
    }

    public function lookupByCode(string $code): array
    {
        $response = $this->request('/database/search', ['barcode' => $code, 'type' => 'release']);

        if ($response === null) {
            return [];
        }

        $results = $response->json('results', []);
        $firstResult = $results[0] ?? null;

        if (! $firstResult) {
            return [];
        }

        $release = $this->fetchReleaseWithCover($results);

        return [$release !== null
            ? $this->mapReleaseToCandidate($release, $code)
            : $this->mapSearchResultToCandidate($firstResult, $code)];
    }

    /**
     * See this class's docblock for the "multiple releases share a
     * barcode, only some have a cover" problem this solves. Checks up to
     * MAX_BARCODE_MATCHES_CHECKED search results' full release records (in
     * the order Discogs itself ranked them) and returns the first with a
     * non-empty `images` array; if none of the checked results have one,
     * falls back to the very first result's release — same outcome as
     * before this method existed — or null if even that single fetch
     * failed (lookupByCode() then falls back further, to the bare search
     * result).
     */
    private function fetchReleaseWithCover(array $results): ?array
    {
        $fallback = null;

        foreach (array_slice($results, 0, self::MAX_BARCODE_MATCHES_CHECKED) as $result) {
            if (! isset($result['id'])) {
                continue;
            }

            $release = $this->fetchRelease($result['id']);

            if ($release === null) {
                continue;
            }

            $fallback ??= $release;

            if (! empty($release['images'])) {
                return $release;
            }
        }

        return $fallback;
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
                // No 'runtime_seconds'/'runtime_computed' here on purpose —
                // Discogs' release record has no field of its own for total
                // runtime, only per-track durations, so a runtime can only
                // ever be *derived* from whichever `tracks` value ends up
                // actually chosen (see MediaItemService::create()). Setting
                // it here too, as its own independently mergeable/pickable
                // attribute, would risk a user ending up with one
                // provider's tracks paired with a *different* provider's
                // runtime number if they picked them independently in the
                // merge review UI (GitHub issue #48) — deriving it once,
                // centrally, from the final chosen `tracks` sidesteps that
                // entirely.
                'tracks' => $this->mapTracklist($release['tracklist'] ?? []),
                'release_date' => $this->normalizeReleaseDate($release['released'] ?? null),
                'ean' => $code,
            ],
            coverUrls: collect($release['images'] ?? [])->pluck('uri')->filter()->values()->all(),
        );
    }

    /**
     * `tracklist` entries can also be section headings/indexes (`type_`
     * other than `"track"`, e.g. multi-disc sets label each disc as its own
     * heading entry) — confirmed live against a real multi-disc release —
     * those aren't real tracks and are filtered out rather than showing up
     * as a bogus track with no duration.
     *
     * @return array<int, array{position: string|null, title: string|null, duration_seconds: int|null}>
     */
    private function mapTracklist(array $tracklist): array
    {
        return collect($tracklist)
            ->filter(fn (array $entry) => ($entry['type_'] ?? 'track') === 'track')
            ->map(fn (array $entry) => [
                'position' => $entry['position'] ?? null,
                'title' => $entry['title'] ?? null,
                'duration_seconds' => $this->parseTrackDuration($entry['duration'] ?? null),
            ])
            ->values()
            ->all();
    }

    /** Discogs formats a track's duration as free-form "M:SS" text (confirmed live) — blank/missing for a good number of real tracks, never assumed present. */
    private function parseTrackDuration(?string $duration): ?int
    {
        if (! $duration || ! preg_match('/^(\d+):(\d{2})$/', trim($duration), $matches)) {
            return null;
        }

        return ((int) $matches[1]) * 60 + (int) $matches[2];
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

    /**
     * See this class's docblock: `released` is one of a full ISO date, a
     * bare "YYYY" year, a "YYYY-MM-00"/"YYYY-00-00" partial date, or null
     * — all confirmed with real live data. Substitutes "01" for a missing
     * or "00" month/day component rather than passing the raw string
     * through, since a `date`-cast column needs a genuine, complete date.
     */
    private function normalizeReleaseDate(?string $released): ?string
    {
        if (! $released || ! preg_match('/^(\d{4})(?:-(\d{2}))?(?:-(\d{2}))?$/', $released, $matches)) {
            return null;
        }

        $month = ($matches[2] ?? '00') !== '00' ? $matches[2] : '01';
        $day = ($matches[3] ?? '00') !== '00' ? $matches[3] : '01';

        return "{$matches[1]}-{$month}-{$day}";
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
