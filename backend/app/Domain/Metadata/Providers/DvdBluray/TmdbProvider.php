<?php

namespace App\Domain\Metadata\Providers\DvdBluray;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderConfigField;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Models\MetadataPlugin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * DVD/Blu-ray metadata plugin using The Movie Database (TMDB) API
 * (GitHub issue #157, following a requested feasibility study). Unlike
 * every other provider in this app, TMDB has no barcode/EAN/UPC lookup
 * capability whatsoever — confirmed against the live API reference for
 * `/find/{external_id}`, whose only supported `external_source` values
 * are `imdb_id`/`tvdb_id`/`wikidata_id` and various social-media IDs, no
 * physical-media identifier of any kind. `lookupByCode()` below therefore
 * always returns `[]`, and `supportsCodeLookup()` (GitHub issue #158)
 * declares that explicitly rather than leaving a caller to infer it from
 * an always-empty result — this is the first provider where that
 * distinction actually matters. TMDB only ever contributes through
 * `search()`, which this app's free-text "ohne EAN erfassen" flow
 * (GitHub issue #151) already calls for every enabled provider — no
 * separate integration point was needed to make TMDB usable as soon as a
 * film's title is known. GitHub issue #159 tracks a possible future
 * second stage: automatically feeding a title resolved via a normal
 * EAN-based capture into a provider like this one, without the user
 * having to switch to the free-text search box themselves.
 *
 * `search()` maps candidates straight from `GET /search/movie`'s own
 * response fields (title, overview, release_date, genre_ids, poster_path)
 * without a follow-up `GET /movie/{id}` call per result — the same
 * "map only what the endpoint actually returns, don't fan out into N
 * extra requests per search" discipline every other search()
 * implementation in this app already follows (e.g. OpenLibraryProvider).
 * The trade-off: TMDB's cast/crew and runtime are genuinely only
 * available via a per-title `append_to_response=credits` detail call,
 * which this provider deliberately does not make — `cast`/`director`/
 * `runtime_minutes` stay unset. `genre_ids` are numeric and need
 * translating to names via a separate `GET /genre/movie/list` call;
 * unlike a per-result detail fetch, this is a single, cacheable lookup
 * (TMDB's genre list is a small, effectively static reference table, not
 * per-title data) reused across every search.
 *
 * Authentication uses TMDB's Bearer "API Read Access Token" (`Http::
 * withToken()`), stored under the same `api_key` config key every other
 * API-key-gated provider in this app uses (e.g. UpcMdbProvider) — the
 * value itself is a longer opaque JWT-shaped string rather than a short
 * key, but the field is otherwise identical (a single required `password`
 * type), so there was no reason to invent a new config key name just for
 * TMDB's own terminology.
 *
 * Marked `version()` `"v0.1-beta"` and left in
 * `MetadataProviderRegistry::DEFAULT_DISABLED_PROVIDER_KEYS` (opt-in),
 * the same treatment Amazon/Claude/OpenAI/Gemini already get — this
 * class was built entirely against TMDB's own published API reference
 * (verified live via a plain, unauthenticated documentation fetch), never
 * against the real, authenticated API itself (no usable credentials
 * available while implementing it). The feasibility study behind GitHub
 * issue #157 also flagged a real, unresolved question worth restating
 * here: TMDB's API Terms of Use prohibit caching API data for longer than
 * six months, in real tension with this app's own purpose of permanent,
 * self-hosted collection storage — a deliberate reason to require an
 * admin's explicit opt-in rather than enabling this by default.
 */
class TmdbProvider implements MetadataProviderInterface
{
    private const BASE_URL = 'https://api.themoviedb.org/3';

    // Documented, effectively permanent image-CDN URL scheme (themoviedb.org's
    // own API reference) — w500 is a good balance for a cover thumbnail/detail
    // view, one of a fixed documented set of poster sizes (w92/w154/w185/w342/
    // w500/w780/original) TMDB itself serves, not a guess.
    private const IMAGE_BASE_URL = 'https://image.tmdb.org/t/p/w500';

    public function key(): string
    {
        return 'dvd_bluray.tmdb';
    }

    public function name(): string
    {
        return 'TMDB';
    }

    public function mediaType(): string
    {
        return 'dvd_bluray';
    }

    public function configFields(): array
    {
        return [
            new MetadataProviderConfigField('api_key', type: 'password', required: true),
        ];
    }

    /** See MetadataProviderInterface::version()'s docblock — never live-verified against the real, authenticated API, see this class's own docblock. */
    public function version(): string
    {
        return 'v0.1-beta';
    }

    /** See MetadataProviderInterface::sourceType()'s docblock — a real, documented API, not scraping. */
    public function sourceType(): string
    {
        return 'api';
    }

    /** See this class's own docblock — TMDB has no barcode/EAN/UPC lookup capability at all. */
    public function supportsCodeLookup(): bool
    {
        return false;
    }

    /** Always empty — see this class's own docblock and supportsCodeLookup() above. */
    public function lookupByCode(string $code): array
    {
        return [];
    }

    public function search(string $query): array
    {
        $token = $this->accessToken();
        if ($token === null) {
            return [];
        }

        $response = Http::withToken($token)->get(self::BASE_URL.'/search/movie', ['query' => $query]);

        if ($response->failed()) {
            return [];
        }

        $genreNames = $this->genreNamesById($token);

        return collect($response->json('results', []))
            ->map(fn (array $item) => $this->mapToCandidate($item, $genreNames))
            ->all();
    }

    /**
     * TMDB's genre list (`GET /genre/movie/list`) is a small, effectively
     * static reference table (the same ~19 movie genres TMDB has used for
     * years) rather than per-title data — cached for a day so a page of
     * search results doesn't cost one lookup per result, the same
     * `Cache::remember()` pattern `SearchService::pgTrgmAvailable()`
     * already uses for similarly small, stable external state. A failed
     * request here degrades to "no genre names available" (candidates
     * still get every other field) rather than failing the whole search.
     *
     * @return array<int, string> Genre id => name.
     */
    private function genreNamesById(string $token): array
    {
        return Cache::remember('tmdb_movie_genres', now()->addDay(), function () use ($token) {
            $response = Http::withToken($token)->get(self::BASE_URL.'/genre/movie/list');

            if ($response->failed()) {
                return [];
            }

            return collect($response->json('genres', []))->pluck('name', 'id')->all();
        });
    }

    /**
     * Read from the same metadata_plugins.config JSON blob the admin UI
     * already edits for this provider (PluginsPage.tsx) — via a fresh
     * Eloquent fetch, not a raw query builder ->value(), so the model's
     * `config` => 'array' cast actually applies instead of returning the
     * raw (possibly still-JSON-encoded) column value. Same pattern
     * UpcMdbProvider::apiKey() already uses.
     */
    private function accessToken(): ?string
    {
        $config = MetadataPlugin::query()->where('provider_key', $this->key())->first()?->config;

        return $config['api_key'] ?? null;
    }

    /** @param  array<int, string>  $genreNames  Genre id => name, from genreNamesById(). */
    private function mapToCandidate(array $item, array $genreNames): MetadataCandidate
    {
        $releaseDate = $this->nullIfEmpty($item['release_date'] ?? null);

        $genre = collect($item['genre_ids'] ?? [])
            ->map(fn ($id) => $genreNames[$id] ?? null)
            ->filter()
            ->implode(', ');

        $posterPath = $item['poster_path'] ?? null;

        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: (string) ($item['id'] ?? ''),
            attributes: [
                'title' => $item['title'] ?? null,
                'description' => $this->nullIfEmpty($item['overview'] ?? null),
                'genre' => $this->nullIfEmpty($genre),
                'release_date' => $releaseDate,
                'production_year' => $releaseDate !== null ? (int) substr($releaseDate, 0, 4) : null,
                // No `medium`/`disc_count`/`languages`/`subtitles`/`cast`/
                // `director`/`runtime_minutes`/`price`/`currency` — none of
                // these are ever available from TMDB (a film database, not
                // a physical-release/retail one) or, for cast/director/
                // runtime specifically, not without the per-result detail
                // fetch this provider deliberately doesn't make (see this
                // class's own docblock).
            ],
            coverUrls: $posterPath ? [self::IMAGE_BASE_URL.$posterPath] : [],
        );
    }

    private function nullIfEmpty(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
