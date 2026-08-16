<?php

namespace App\Domain\Metadata\Providers\Book;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderConfigField;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Models\MetadataPlugin;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google Books metadata plugin (briefing 8.2 — Buch, GitHub issue #20).
 * Unlike OpenLibraryProvider (truly open, no key concept at all), the
 * Volumes API (https://www.googleapis.com/books/v1/volumes) is reachable
 * without any credentials but only under a very small shared daily quota —
 * confirmed live during implementation: unauthenticated requests from this
 * environment immediately returned HTTP 429
 * ("Quota exceeded ... consumer 'project_number:...'"), matching Google's
 * own documentation, which recommends an API key for anything beyond
 * occasional testing. `configFields()` therefore declares an *optional*
 * `api_key` (unlike UpcMdbProvider's, which is required and the request
 * can't be made without it at all) — this provider still functions with no
 * config, just against that small shared quota, and an admin-supplied key
 * (metadata_plugins.config, briefing 15.) raises it via the documented
 * `key` query parameter.
 */
class GoogleBooksProvider implements MetadataProviderInterface
{
    private const BASE_URL = 'https://www.googleapis.com/books/v1/volumes';

    public function key(): string
    {
        return 'book.google_books';
    }

    public function name(): string
    {
        return 'Google Books';
    }

    public function mediaType(): string
    {
        return 'book';
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
        $response = $this->request(['q' => "isbn:{$code}"]);

        if ($response === null) {
            return [];
        }

        $item = $response->json('items.0');

        if (! $item) {
            return [];
        }

        return [$this->mapToCandidate($this->fetchFullVolume($item), $code)];
    }

    public function search(string $query): array
    {
        $response = $this->request(['q' => $query, 'maxResults' => 10]);

        if ($response === null) {
            return [];
        }

        // Deliberately not enriched via fetchFullVolume() here (unlike
        // lookupByCode()) — doing so for every one of up to 10 search hits
        // would multiply the request count against Google's already tight
        // shared quota (see this class's docblock) for a secondary/manual
        // lookup path. Same asymmetry OpenLibraryProvider uses: its extra
        // Editions-API call (issue #28) is only made from lookupByCode() too.
        return collect($response->json('items', []))
            ->map(fn (array $item) => $this->mapToCandidate($item, null))
            ->all();
    }

    /**
     * The search endpoint (`?q=...`) returns an abbreviated `volumeInfo` —
     * confirmed live (GitHub issue #20 follow-up, reported against EAN
     * 9783742310026 "Murdoku"): its response omitted `publisher` entirely,
     * even though a dedicated GET of the same volume ID
     * (.../v1/volumes/{id}) includes it. Fetches the canonical by-ID record
     * and prefers it, falling back to the original (still partially useful)
     * search-result item if that second call fails — same two-call pattern
     * as OpenLibraryProvider's Books-API + Editions-API split (issue #28).
     */
    private function fetchFullVolume(array $item): array
    {
        $id = $item['id'] ?? null;

        if ($id === null) {
            return $item;
        }

        $response = $this->request([], $id);

        return $response?->json() ?? $item;
    }

    private function request(array $query, ?string $volumeId = null): ?Response
    {
        if ($apiKey = $this->apiKey()) {
            $query['key'] = $apiKey;
        }

        $url = $volumeId === null ? self::BASE_URL : self::BASE_URL.'/'.$volumeId;
        $response = Http::get($url, $query);

        return $response->successful() ? $response : null;
    }

    /** Same runtime-configured-secret pattern as UpcMdbProvider::apiKey() — see that class's docblock. */
    private function apiKey(): ?string
    {
        $config = MetadataPlugin::query()->where('provider_key', $this->key())->first()?->config;

        return $config['api_key'] ?? null;
    }

    private function mapToCandidate(array $item, ?string $code): MetadataCandidate
    {
        $info = $item['volumeInfo'] ?? [];
        $identifiers = collect($info['industryIdentifiers'] ?? []);

        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: (string) ($item['id'] ?? $code ?? ''),
            attributes: [
                'title' => $info['title'] ?? null,
                'authors' => implode(', ', $info['authors'] ?? []),
                // Google Books is a digital catalog, not a physical-media
                // database — it has no "physical_format" concept (paperback/
                // hardcover/...) at all, unlike OpenLibrary's Editions API, so
                // this is left unset rather than guessed from other fields.
                'genre' => implode(', ', $info['categories'] ?? []),
                // Google uses a literal 0 (not an absent key) as its "unknown
                // page count" placeholder for sparsely-catalogued records —
                // observed live for EAN 9783742310026 ("Murdoku"). No real
                // print book has zero pages, so 0 is treated as unknown too.
                'page_count' => ! empty($info['pageCount']) ? $info['pageCount'] : null,
                'language' => $info['language'] ?? null,
                'publisher' => $info['publisher'] ?? null,
                'release_date' => $info['publishedDate'] ?? null,
                'isbn13' => $identifiers->firstWhere('type', 'ISBN_13')['identifier'] ?? null,
                'isbn10' => $identifiers->firstWhere('type', 'ISBN_10')['identifier'] ?? null,
                'ean' => $code,
            ],
            coverUrls: $this->coverUrls($info['imageLinks'] ?? []),
        );
    }

    /**
     * Building the array by explicitly mapping the preferred key order,
     * rather than Collection::only(['large', ..., 'smallThumbnail'])
     * (which does NOT reorder — it filters to those keys while preserving
     * whichever order the source `imageLinks` object itself used, so the
     * previous code silently put whatever Google listed first, typically
     * `smallThumbnail`, into cover_urls[0] regardless of the argument
     * order's apparent intent).
     *
     * Every real response observed live for this class (both during
     * initial implementation and the "cover is much too small" follow-up)
     * only ever had `thumbnail`/`smallThumbnail` at all — `large`/`medium`/
     * `small`/`extraLarge` are listed defensively per Google's documented
     * schema but have never actually been seen. Both observed URLs encode
     * a `zoom` query parameter that controls the returned resolution
     * (confirmed live against books.google.com/books/content: zoom=1, the
     * "thumbnail" default, is a mere 128x198px; zoom=3 reliably returns a
     * genuinely large ~575x889px cover; zoom=5 and above wrap back around
     * to the smallest size instead of erroring) — upgradeZoom() rewrites
     * it to request that larger size instead of trusting whichever
     * (small) rendition Google linked by default.
     */
    private function coverUrls(array $imageLinks): array
    {
        return collect(['extraLarge', 'large', 'medium', 'small', 'thumbnail', 'smallThumbnail'])
            ->map(fn (string $size) => $imageLinks[$size] ?? null)
            ->filter()
            ->map(fn (string $url) => $this->upgradeZoom(preg_replace('#^http://#', 'https://', $url)))
            ->values()
            ->all();
    }

    /** No-op for a URL that never had a `zoom` parameter to begin with (e.g. a genuine `large`/`extraLarge` link, if Google ever actually sends one). */
    private function upgradeZoom(string $url): string
    {
        return preg_replace('/([?&])zoom=\d+/', '$1zoom=3', $url) ?? $url;
    }
}
