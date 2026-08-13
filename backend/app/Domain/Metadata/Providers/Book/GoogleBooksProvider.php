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

        return [$this->mapToCandidate($item, $code)];
    }

    public function search(string $query): array
    {
        $response = $this->request(['q' => $query, 'maxResults' => 10]);

        if ($response === null) {
            return [];
        }

        return collect($response->json('items', []))
            ->map(fn (array $item) => $this->mapToCandidate($item, null))
            ->all();
    }

    private function request(array $query): ?Response
    {
        if ($apiKey = $this->apiKey()) {
            $query['key'] = $apiKey;
        }

        $response = Http::get(self::BASE_URL, $query);

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
                'page_count' => $info['pageCount'] ?? null,
                'language' => $info['language'] ?? null,
                'publisher' => $info['publisher'] ?? null,
                'release_date' => $info['publishedDate'] ?? null,
                'isbn13' => $identifiers->firstWhere('type', 'ISBN_13')['identifier'] ?? null,
                'isbn10' => $identifiers->firstWhere('type', 'ISBN_10')['identifier'] ?? null,
                'ean' => $code,
            ],
            // imageLinks URLs are documented (and observed live) as http://,
            // not https:// — CapturePage.tsx renders these directly as
            // <img src>, so upgrading avoids a mixed-content block on a
            // deployment served over https (briefing 16., MEDINV_URL).
            coverUrls: collect($info['imageLinks'] ?? [])
                ->only(['large', 'medium', 'small', 'thumbnail', 'smallThumbnail'])
                ->map(fn (string $url) => preg_replace('#^http://#', 'https://', $url))
                ->values()
                ->all(),
        );
    }
}
