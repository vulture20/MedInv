<?php

namespace App\Domain\Metadata\Providers\DvdBluray;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderConfigField;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\Contracts\TestableMetadataProvider;
use App\Domain\Metadata\MetadataProviderRequestException;
use App\Models\MetadataPlugin;
use Illuminate\Support\Facades\Auth;
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
 * `search()` maps most candidates straight from `GET /search/movie`'s own
 * response fields (title, overview, release_date, genre_ids, poster_path)
 * — the same "map only what the endpoint actually returns, don't fan out
 * into N extra requests per search" discipline every other search()
 * implementation in this app already follows (e.g. OpenLibraryProvider).
 * `genre_ids` are numeric and need translating to names via a separate
 * `GET /genre/movie/list` call; unlike a per-result detail fetch, this is
 * a single, cacheable lookup (TMDB's genre list is a small, effectively
 * static reference table, not per-title data) reused across every search.
 *
 * Cast/crew and runtime (GitHub issue #165) are a deliberate partial
 * exception to that "no extra requests" rule: genuinely only available
 * via a per-title `GET /movie/{id}` detail call (`runtime` directly,
 * `credits.cast[]`/`credits.crew[]` — Regie via `job === "Director"` — via
 * `append_to_response=credits`, field names confirmed live against the
 * official reference), too valuable to leave unset entirely given how
 * unreliable this data already is everywhere else in this app (Amazon's
 * `cast` extraction was removed outright, GitHub issue #150; JPC has no
 * confirmed cast label at all). Fetching it for *every* search result
 * would still be the same N+1 fan-out this class otherwise avoids, so
 * only the first `MAX_ENRICHED_RESULTS` results (1, for now — an
 * explicit, deliberately conservative starting point, not a permanent
 * ceiling) get the extra call; every other result stays exactly as
 * unenriched as before. Worth remembering this compounds with GitHub
 * issue #159's own second-stage round, which can search up to 3 different
 * titles per single EAN scan (`MAX_TITLE_CANDIDATES`) — up to 3 extra
 * detail requests per scan, not per manual search, now that round 2 runs
 * automatically. movieDetailsWithCredits() degrades to `null` (the
 * un-enriched candidate) on any failure rather than failing the whole
 * search, the same resilience genreNamesById() already has.
 *
 * Authentication uses TMDB's Bearer "API Read Access Token" (`Http::
 * withToken()`), stored under its own `read_access_token` config key —
 * *not* the `api_key` key every other API-key-gated provider in this app
 * uses (e.g. UpcMdbProvider), even though the field is otherwise
 * identical (a single required `password` type). This was reused as
 * `api_key` in this class's first version and corrected after the user
 * pointed out the real, "akute Verwechslungsgefahr" this created: TMDB
 * itself issues two genuinely different credentials — a short v3 "API
 * Key" and this long, JWT-shaped "API Read Access Token" — and this
 * provider needs the latter, not the former. Labeling the settings-dialog
 * field "API-Key" (`admin.pluginConfig.fields.api_key`, the shared label
 * every other provider's identical-looking field already uses) would
 * have actively invited an admin to paste the wrong one of the two in.
 * The field's `key` is what PluginsPage.tsx resolves a translated label
 * from (`admin.pluginConfig.fields.<key>`), so giving this field its own
 * distinct key was the only way to give it its own distinct, correct
 * label ("API-Token für Lesezugriff") without also relabeling every
 * other provider's genuinely-a-plain-API-key field.
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
class TmdbProvider implements MetadataProviderInterface, TestableMetadataProvider
{
    private const BASE_URL = 'https://api.themoviedb.org/3';

    // Documented, effectively permanent image-CDN URL scheme (themoviedb.org's
    // own API reference) — w500 is a good balance for a cover thumbnail/detail
    // view, one of a fixed documented set of poster sizes (w92/w154/w185/w342/
    // w500/w780/original) TMDB itself serves, not a guess.
    private const IMAGE_BASE_URL = 'https://image.tmdb.org/t/p/w500';

    /** GitHub issue #165 — see this class's own docblock for why this is 1, not "every result". */
    private const MAX_ENRICHED_RESULTS = 1;

    /** GitHub issue #165 — see castFrom()'s own docblock for why the full cast list isn't used as-is. */
    private const MAX_CAST_MEMBERS = 10;

    /**
     * GitHub issue #170: TMDB's `language` query parameter (search, movie
     * details, and the genre list — everywhere below a request is made)
     * expects a full `ISO-639-1-ISO-3166-1` tag, not the bare two-letter
     * codes this app stores in `User::preferred_language` (e.g. 'de', not
     * 'de-DE') — confirmed against TMDB's own reference documentation
     * (`GET /search/movie`, defaulting to "en-US") and its
     * `GET /configuration/primary_translations` example response, which
     * lists a specific region for every one of this app's supported
     * languages except Icelandic (not present in that list at all — 'is-IS'
     * below is the standard ISO combination, not itself TMDB-confirmed).
     * Kept as an explicit map rather than a guessed `"{code}-{code
     * uppercased}"` pattern, since that would be wrong for several real
     * entries here (e.g. 'uk' is Ukrainian, not the UK; TMDB's own list
     * uses 'zh-CN'/'pt-PT'/'no-NO', not the only-imaginable options for
     * those). A preferred_language without an entry here (a future
     * language pack this map hasn't been extended for yet) falls back to
     * TMDB's own "en-US" default rather than sending a malformed value.
     */
    private const TMDB_LANGUAGE_BY_CODE = [
        'de' => 'de-DE',
        'en' => 'en-US',
        'es' => 'es-ES',
        'fi' => 'fi-FI',
        'fr' => 'fr-FR',
        'is' => 'is-IS',
        'it' => 'it-IT',
        'ja' => 'ja-JP',
        'nl' => 'nl-NL',
        'no' => 'no-NO',
        'pl' => 'pl-PL',
        'pt' => 'pt-PT',
        'ru' => 'ru-RU',
        'sv' => 'sv-SE',
        'tr' => 'tr-TR',
        'uk' => 'uk-UA',
        'zh' => 'zh-CN',
    ];

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
            new MetadataProviderConfigField('read_access_token', type: 'password', required: true),
        ];
    }

    /** See MetadataProviderInterface::version()'s docblock — never live-verified against the real, authenticated API, see this class's own docblock. Bumped for GitHub issue #170's language-aware search. */
    public function version(): string
    {
        return 'v0.3-beta';
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

    /**
     * GitHub issue #160, following the user's own explicit request after
     * #157: `GET /authentication` is TMDB's own dedicated endpoint for
     * exactly this — validating a token without any other side effect —
     * confirmed live against the API reference, including the precise
     * 200-vs-401 distinction the user themselves already anticipated: a
     * valid token gets `200 {"success": true, ...}`, an invalid/expired
     * one gets `401 {"success": false, "status_code": 7, ...}`. Anything
     * else (a network error, a 5xx, an unexpected status) means the check
     * itself didn't complete — thrown rather than folded into `false`, the
     * same "don't conflate 'confirmed invalid' with 'couldn't check'"
     * distinction GitHub issue #53 already established for lookupByCode()
     * failures elsewhere in this app.
     */
    public function testConfig(array $config): bool
    {
        $token = $config['read_access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            return false;
        }

        $response = Http::withToken($token)->get(self::BASE_URL.'/authentication');

        if ($response->successful()) {
            return true;
        }

        if ($response->status() === 401) {
            return false;
        }

        throw new MetadataProviderRequestException("TMDB config test failed with status {$response->status()}.");
    }

    public function search(string $query): array
    {
        $token = $this->accessToken();
        if ($token === null) {
            return [];
        }

        $language = $this->tmdbLanguage();
        $response = Http::withToken($token)->get(self::BASE_URL.'/search/movie', ['query' => $query, 'language' => $language]);

        if ($response->failed()) {
            return [];
        }

        $genreNames = $this->genreNamesById($token, $language);

        return collect($response->json('results', []))
            ->values()
            ->map(function (array $item, int $index) use ($genreNames, $token, $language) {
                // GitHub issue #165: only the first MAX_ENRICHED_RESULTS
                // results get the extra detail+credits request — see this
                // class's own docblock for why not every result does.
                $details = $index < self::MAX_ENRICHED_RESULTS && isset($item['id'])
                    ? $this->movieDetailsWithCredits((int) $item['id'], $token, $language)
                    : null;

                return $this->mapToCandidate($item, $genreNames, $details);
            })
            ->all();
    }

    /**
     * GitHub issue #170: the requesting user's own preferred_language,
     * translated to the full locale tag TMDB's `language` parameter
     * expects (TMDB_LANGUAGE_BY_CODE above). Read straight off `Auth::
     * user()` rather than threaded through as a parameter — every call
     * site of `search()` runs within an authenticated admin/user request
     * (MetadataProviderInterface's own signature is shared across all ~20
     * providers and fixed, not something to widen for one provider's
     * benefit), the same tolerant 'de' fallback PdfExportService::
     * languageFor() already uses for a factory-built or otherwise
     * languageless user.
     */
    private function tmdbLanguage(): string
    {
        $code = Auth::user()?->preferred_language ?: 'de';

        return self::TMDB_LANGUAGE_BY_CODE[$code] ?? 'en-US';
    }

    /**
     * `runtime` (top-level) and `credits.cast[]`/`credits.crew[]` (via
     * `append_to_response=credits`) — the only source for any of these
     * three fields, see this class's own docblock. Returns null on any
     * failure (a missing/expired token already returned earlier via
     * search()'s own guard never reaches this method at all) so the
     * calling result stays exactly as useful as it would have been without
     * enrichment, rather than losing the whole candidate over one extra,
     * genuinely optional request failing.
     */
    private function movieDetailsWithCredits(int $id, string $token, string $language): ?array
    {
        $response = Http::withToken($token)->get(self::BASE_URL."/movie/{$id}", [
            'append_to_response' => 'credits',
            'language' => $language,
        ]);

        return $response->successful() ? $response->json() : null;
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
     * Cache key includes `$language` (GitHub issue #170) — genre names are
     * themselves translated by TMDB, so a single shared cache entry would
     * otherwise serve one user's language to every other user regardless
     * of their own preference, whoever happened to trigger the first
     * lookup within the cache's one-day lifetime.
     *
     * @return array<int, string> Genre id => name.
     */
    private function genreNamesById(string $token, string $language): array
    {
        return Cache::remember("tmdb_movie_genres_{$language}", now()->addDay(), function () use ($token, $language) {
            $response = Http::withToken($token)->get(self::BASE_URL.'/genre/movie/list', ['language' => $language]);

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
     * UpcMdbProvider::apiKey() already uses, just keyed on
     * `read_access_token` rather than `api_key` — see configFields()'s
     * own comment for why those two are deliberately not the same key.
     */
    private function accessToken(): ?string
    {
        $config = MetadataPlugin::query()->where('provider_key', $this->key())->first()?->config;

        return $config['read_access_token'] ?? null;
    }

    /**
     * @param  array<int, string>  $genreNames  Genre id => name, from genreNamesById().
     * @param  ?array  $details  The enriched movie-details+credits response for this result (movieDetailsWithCredits()), or null when this result wasn't one of the first MAX_ENRICHED_RESULTS, or the enrichment request failed.
     */
    private function mapToCandidate(array $item, array $genreNames, ?array $details): MetadataCandidate
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
                // GitHub issue #165 — null for every result but the first
                // (MAX_ENRICHED_RESULTS), same as before this issue.
                'runtime_minutes' => $details['runtime'] ?? null,
                'director' => $this->directorFrom($details),
                'cast' => $this->castFrom($details),
                // No `medium`/`disc_count`/`languages`/`subtitles`/`price`/
                // `currency` — none of these are ever available from TMDB
                // (a film database, not a physical-release/retail one).
            ],
            coverUrls: $posterPath ? [self::IMAGE_BASE_URL.$posterPath] : [],
        );
    }

    /** `credits.crew[]` entries with `job === "Director"` (confirmed live against the official reference), joined for the rare case of more than one credited director. Null without `$details` or a confirmed director. */
    private function directorFrom(?array $details): ?string
    {
        if ($details === null) {
            return null;
        }

        $directors = collect($details['credits']['crew'] ?? [])
            ->filter(fn (array $member) => ($member['job'] ?? null) === 'Director')
            ->pluck('name')
            ->filter()
            ->implode(', ');

        return $this->nullIfEmpty($directors);
    }

    /**
     * `credits.cast[]`, sorted by TMDB's own `order` field (billing order —
     * confirmed live against the official reference) and capped to
     * MAX_CAST_MEMBERS: a real cast list can run to 50+ entries including
     * uncredited/minor roles, which isn't a usable value for this app's
     * plain free-text `cast` field the way a handful of the actually
     * top-billed names is. Null without `$details` or any cast entries.
     */
    private function castFrom(?array $details): ?string
    {
        if ($details === null) {
            return null;
        }

        $cast = collect($details['credits']['cast'] ?? [])
            ->sortBy('order')
            ->take(self::MAX_CAST_MEMBERS)
            ->pluck('name')
            ->filter()
            ->implode(', ');

        return $this->nullIfEmpty($cast);
    }

    private function nullIfEmpty(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
