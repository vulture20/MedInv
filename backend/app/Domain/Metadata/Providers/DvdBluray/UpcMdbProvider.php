<?php

namespace App\Domain\Metadata\Providers\DvdBluray;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderConfigField;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\MetadataProviderRequestException;
use App\Models\MetadataPlugin;
use Illuminate\Support\Facades\Http;

/**
 * DVD/Blu-ray metadata plugin using the UPCMDB API (briefing 8.2 —
 * DVD/Blu-ray). Previously implemented against the unrelated upcitemdb.com
 * service under the same class/provider-key name — a mistranscription
 * corrected here; UPCMDB (https://upcmdb.com/) is a distinct, movie-specific
 * physical-media database (title/IMDb-ID/format/cast/... for DVD, Blu-ray,
 * 4K releases) and requires an API key, unlike the free-tier lookup this
 * class used to call. Amazon and Emunation.ch, the other two briefing 8.2
 * sources for this media type, follow the same shape under this namespace.
 *
 * The API key is admin-configured per briefing 15.'s existing mechanism for
 * this — metadata_plugins.config (see MetadataController::updatePlugin()) —
 * rather than a new system_settings entry or MEDINV_* env var, since it's
 * already exactly the place this app stores per-provider configuration.
 *
 * `genre` (GitHub issue #140) *is* one of UPCMDB's documented response
 * fields — it was already present in this class's own test fixture (built
 * from the real API reference) but never mapped into a candidate until
 * MediaDvdBluray gained a `genre` column to map it onto. `subtitles` isn't
 * documented anywhere in that same reference, so `$item['subtitles']` is a
 * best-effort key guess that simply stays null if wrong, the same
 * "plausible guess, not a confirmed field" caution this session applied to
 * Amazon's equivalent field.
 */
class UpcMdbProvider implements MetadataProviderInterface
{
    private const BASE_URL = 'https://us-central1-upcmdb-cbae5.cloudfunctions.net/api';

    public function key(): string
    {
        return 'dvd_bluray.upcmdb';
    }

    public function name(): string
    {
        return 'UPCMDB';
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

    /** See MetadataProviderInterface::version()'s docblock (GitHub issue #44). */
    public function version(): string
    {
        return 'v1.0';
    }

    /** See MetadataProviderInterface::sourceType()'s docblock (GitHub issue #55) — a real, documented API. */
    public function sourceType(): string
    {
        return 'api';
    }

    /**
     * This app's own `ean` column is a 13-digit EAN (see the media_dvd_blurays
     * migration), so /v1/lookup/ean/:ean — not the separate UPC-12 or IMDb-ID
     * endpoints UPCMDB also offers — is the correct match for what gets
     * scanned/entered here.
     */
    public function lookupByCode(string $code): array
    {
        $apiKey = $this->apiKey();

        // A missing required api_key (configFields() above) is exactly the
        // "falsch konfigurierter API-Key" case GitHub issue #53 is about —
        // reported as 'failed', not silently as 'no_match'.
        if ($apiKey === null) {
            throw new MetadataProviderRequestException('UPCMDB request skipped: no api_key configured.');
        }

        $response = Http::withHeader('x-api-key', $apiKey)->get(self::BASE_URL.'/v1/lookup/ean/'.$code);

        // 404 ("UPC not found in database") is UPCMDB's own genuine
        // no-match signal, unlike any other failure (401/403/429, see
        // UPCMDB's documented error codes) — a wrong/expired key or a rate
        // limit is the request itself not succeeding, distinct from #53's
        // 'no_match', so only 404 stays a silent empty result.
        if ($response->status() === 404) {
            return [];
        }

        if ($response->failed()) {
            throw new MetadataProviderRequestException("UPCMDB request failed with status {$response->status()}.");
        }

        // Unlike a search, a single lookup returns one JSON object directly,
        // not an array/items envelope.
        return [$this->mapToCandidate($response->json() ?? [], $code)];
    }

    public function search(string $query): array
    {
        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            return [];
        }

        $response = Http::withHeader('x-api-key', $apiKey)->get(self::BASE_URL.'/v1/search', ['title' => $query]);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json() ?? [])
            ->map(fn (array $item) => $this->mapToCandidate($item, null))
            ->all();
    }

    /**
     * Read from the same metadata_plugins.config JSON blob the admin UI
     * already edits for this provider (PluginsPage.tsx) — via a fresh
     * Eloquent fetch, not a raw query builder ->value(), so the model's
     * `config` => 'array' cast actually applies instead of returning the
     * raw (possibly still-JSON-encoded) column value.
     */
    private function apiKey(): ?string
    {
        $config = MetadataPlugin::query()->where('provider_key', $this->key())->first()?->config;

        return $config['api_key'] ?? null;
    }

    private function mapToCandidate(array $item, ?string $ean): MetadataCandidate
    {
        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: $item['upc'] ?? ($ean ?? ''),
            attributes: [
                'title' => $item['title'] ?? null,
                'description' => $item['plot'] ?? null,
                'medium' => $item['format'] ?? null,
                'director' => $item['director'] ?? null,
                'cast' => $item['actors'] ?? null,
                // GitHub issue #140: `genre` *is* a documented UPCMDB
                // response field (see this class's own test fixture,
                // already modeled on the real API reference, which already
                // carried a 'genre' => 'Drama, War' entry nobody had mapped
                // yet) — `subtitles` is not documented anywhere in that
                // same reference, so it's a best-effort key guess that
                // simply stays null if wrong.
                'genre' => $item['genre'] ?? null,
                'subtitles' => $item['subtitles'] ?? null,
                'production_year' => $item['year'] ?? null,
                'runtime_minutes' => $this->parseRuntimeMinutes($item['runtime'] ?? null),
                'ean' => $ean ?? $item['upc'] ?? null,
            ],
            // UPCMDB's documented response object carries no cover-image field
            // (unlike the previous upcitemdb.com integration) — no cover to offer.
            coverUrls: [],
        );
    }

    /** UPCMDB returns runtime as e.g. "116 min" rather than a bare integer. */
    private function parseRuntimeMinutes(?string $runtime): ?int
    {
        if ($runtime === null || ! preg_match('/(\d+)/', $runtime, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }
}
