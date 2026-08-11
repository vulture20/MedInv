<?php

namespace App\Domain\Metadata\Providers\Book;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use Illuminate\Support\Facades\Http;

/**
 * Reference implementation of a metadata plugin, targeting the free
 * Open Library API (briefing 8.2 — Buch). The other three listed book
 * sources (Hardcover, Amazon, Google Books) follow the same shape: a class
 * implementing MetadataProviderInterface under this namespace, registered
 * in MetadataProviderRegistry::defaultProviders().
 */
class OpenLibraryProvider implements MetadataProviderInterface
{
    public function key(): string
    {
        return 'book.open_library';
    }

    public function name(): string
    {
        return 'Open Library';
    }

    public function mediaType(): string
    {
        return 'book';
    }

    public function lookupByCode(string $code): array
    {
        $response = Http::get('https://openlibrary.org/api/books', [
            'bibkeys' => "ISBN:{$code}",
            'format' => 'json',
            'jscmd' => 'data',
        ]);

        if ($response->failed()) {
            return [];
        }

        $entry = $response->json("ISBN:{$code}");

        if (! $entry) {
            return [];
        }

        return [$this->mapToCandidate($code, $entry)];
    }

    public function search(string $query): array
    {
        $response = Http::get('https://openlibrary.org/search.json', ['q' => $query, 'limit' => 10]);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json('docs', []))
            ->map(fn (array $doc) => new MetadataCandidate(
                providerKey: $this->key(),
                sourceId: (string) ($doc['key'] ?? $doc['edition_key'][0] ?? $query),
                attributes: [
                    'title' => $doc['title'] ?? null,
                    'authors' => implode(', ', $doc['author_name'] ?? []),
                    'publisher' => $doc['publisher'][0] ?? null,
                    'release_date' => isset($doc['first_publish_year']) ? "{$doc['first_publish_year']}-01-01" : null,
                    'language' => $doc['language'][0] ?? null,
                ],
                coverUrls: isset($doc['cover_i'])
                    ? ["https://covers.openlibrary.org/b/id/{$doc['cover_i']}-L.jpg"]
                    : [],
            ))
            ->all();
    }

    private function mapToCandidate(string $code, array $entry): MetadataCandidate
    {
        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: $code,
            attributes: [
                'title' => $entry['title'] ?? null,
                'authors' => collect($entry['authors'] ?? [])->pluck('name')->implode(', '),
                'publisher' => collect($entry['publishers'] ?? [])->pluck('name')->first(),
                'page_count' => $entry['number_of_pages'] ?? null,
                'release_date' => $entry['publish_date'] ?? null,
                'ean' => $code,
                'isbn13' => strlen($code) === 13 ? $code : null,
                'isbn10' => strlen($code) === 10 ? $code : null,
            ],
            coverUrls: collect($entry['cover'] ?? [])->only(['large', 'medium', 'small'])->values()->all(),
        );
    }
}
