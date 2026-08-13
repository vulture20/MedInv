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

    /** Open Library's read API is free and unauthenticated — nothing to configure. */
    public function configFields(): array
    {
        return [];
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
        // The Books API queried above (jscmd=data) is convenient — it resolves
        // author references to names and gives ready-made cover URLs — but it
        // has two gaps, both confirmed live against the real API (GitHub issue
        // #28, reported against EAN/ISBN-13 9783823700166):
        //  - it never includes `physical_format` at all, for any edition
        //    tested (with or without authors present), so `format` can't be
        //    read from $entry no matter what;
        //  - for editions with multiple authors it sometimes omits `authors`
        //    entirely, even though the underlying edition record does list
        //    them — just as unresolved /authors/{id} references rather than
        //    names (observed for 9783823700166, a 4-author book; a 1-author
        //    edition looked up the same way had `authors` resolved fine).
        // The raw Editions API (openlibrary.org/isbn/{isbn}.json) has both —
        // physical_format directly, and the authors as resolvable references
        // — so it's fetched as a second call and used to fill both gaps.
        $edition = $this->fetchEdition($code);

        $authors = collect($entry['authors'] ?? [])->pluck('name')->filter()->implode(', ');
        if ($authors === '') {
            $authors = $this->resolveAuthorNames($edition['authors'] ?? []);
        }

        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: $code,
            attributes: [
                'title' => $entry['title'] ?? $edition['title'] ?? null,
                'authors' => $authors,
                'publisher' => collect($entry['publishers'] ?? [])->pluck('name')->first()
                    ?? ($edition['publishers'][0] ?? null),
                'page_count' => $entry['number_of_pages'] ?? $edition['number_of_pages'] ?? null,
                'release_date' => $entry['publish_date'] ?? $edition['publish_date'] ?? null,
                'ean' => $code,
                'isbn13' => $entry['identifiers']['isbn_13'][0]
                    ?? ($edition['isbn_13'][0] ?? null)
                    ?? (strlen($code) === 13 ? $code : null),
                'isbn10' => $entry['identifiers']['isbn_10'][0]
                    ?? ($edition['isbn_10'][0] ?? null)
                    ?? (strlen($code) === 10 ? $code : null),
                'format' => $edition['physical_format'] ?? null,
            ],
            coverUrls: collect($entry['cover'] ?? [])->only(['large', 'medium', 'small'])->values()->all(),
        );
    }

    /** Raw Editions API record for this ISBN — see mapToCandidate()'s docblock for why this is fetched in addition to the Books API. */
    private function fetchEdition(string $code): array
    {
        $response = Http::get("https://openlibrary.org/isbn/{$code}.json");

        return $response->successful() ? ($response->json() ?? []) : [];
    }

    /**
     * Resolves raw `[{"key": "/authors/OL...A"}, ...]` references (the shape
     * the Editions API — unlike the Books API — always uses for authors) into
     * names, one request per author. Only reached as a fallback when the
     * Books API's own (already-resolved) `authors` field came back empty.
     */
    private function resolveAuthorNames(array $authorRefs): string
    {
        return collect($authorRefs)
            ->pluck('key')
            ->filter()
            ->map(function (string $key) {
                $response = Http::get("https://openlibrary.org{$key}.json");

                return $response->successful() ? $response->json('name') : null;
            })
            ->filter()
            ->implode(', ');
    }
}
