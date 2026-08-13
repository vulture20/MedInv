<?php

namespace App\Domain\Metadata\Providers\Book;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderConfigField;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Models\MetadataPlugin;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Hardcover metadata plugin (briefing 8.2 — Buch, GitHub issue #18).
 * Hardcover's GraphQL API (https://api.hardcover.app/v1/graphql, currently
 * in beta) requires a personal API token an admin generates on their own
 * Hardcover account (hardcover.app/account/api) — unlike OpenLibrary's
 * fully open API, so `configFields()` declares it required, the same
 * shape as UpcMdbProvider's.
 *
 * Token format gotcha, confirmed via Hardcover's own docs plus two
 * independent third-party integrations that separately documented hitting
 * it (booklore, grimmory): the token as displayed/copied from Hardcover's
 * account page reads "Bearer eyJ...", i.e. it already includes the
 * "Bearer " prefix in what you'd naively copy — but the value stored here
 * (metadata_plugins.config.api_key, same convention as every other
 * provider's key) should be the raw token, since this class adds the
 * "Bearer " prefix itself when building the Authorization header.
 * apiKey() strips a leading "Bearer " defensively in case an admin pastes
 * the full copied string anyway.
 *
 * Query design: lookupByCode() is built directly off Hardcover's own
 * literal, official example query ("Get Edition Details by ISBN" in their
 * Editions schema docs) — high confidence. search() calls Hardcover's
 * Typesense-backed `search` field, whose *available fields per
 * query_type* are documented in full, but whose actual response envelope
 * shape is never shown as a literal example (only "the same Typesense
 * index used on the website") — inferred from Typesense's own
 * well-documented, engine-level {hits: [{document: {...}}]} response
 * convention and coded defensively (an unexpected shape degrades to an
 * empty result, see search()) rather than assumed to be exactly right.
 * Unlike OpenLibraryProvider/GoogleBooksProvider, this could not be
 * live-verified against the real API in this environment — doing so
 * requires a real Hardcover account and personal token, which wasn't
 * available; ask before assuming this needs redoing.
 */
class HardcoverProvider implements MetadataProviderInterface
{
    private const GRAPHQL_URL = 'https://api.hardcover.app/v1/graphql';

    public function key(): string
    {
        return 'book.hardcover';
    }

    public function name(): string
    {
        return 'Hardcover';
    }

    public function mediaType(): string
    {
        return 'book';
    }

    public function configFields(): array
    {
        return [
            new MetadataProviderConfigField('api_key', type: 'password', required: true),
        ];
    }

    /**
     * Hardcover's `editions` table has both isbn_10 and isbn_13 as plain,
     * independently-filterable columns (see the official "Get Edition
     * Details by ISBN" example this mirrors) — matched with `_or` so this
     * works regardless of which length code was scanned, rather than
     * guessing from the string length the way some other providers do.
     */
    public function lookupByCode(string $code): array
    {
        $response = $this->query(<<<'GRAPHQL'
            query EditionByIsbn($code: String!) {
                editions(where: {_or: [{isbn_13: {_eq: $code}}, {isbn_10: {_eq: $code}}]}, limit: 1) {
                    isbn_10
                    isbn_13
                    pages
                    release_date
                    physical_format
                    publisher {
                        name
                    }
                    image {
                        url
                    }
                    book {
                        title
                        description
                        contributions {
                            author {
                                name
                            }
                        }
                    }
                    language {
                        language
                    }
                }
            }
            GRAPHQL, ['code' => $code]);

        if ($response === null) {
            return [];
        }

        $edition = $response->json('data.editions.0');

        if (! $edition) {
            return [];
        }

        return [$this->mapEditionToCandidate($edition, $code)];
    }

    public function search(string $query): array
    {
        $response = $this->query(<<<'GRAPHQL'
            query SearchBooks($query: String!) {
                search(query: $query, query_type: "Book", per_page: 10, page: 1) {
                    results
                }
            }
            GRAPHQL, ['query' => $query]);

        if ($response === null) {
            return [];
        }

        // See this class's docblock: the exact shape of `results` is inferred
        // from Typesense's standard {hits: [{document: {...}}]} response, not
        // confirmed against a real Hardcover response — an unexpected shape
        // here (e.g. a future API change, or this inference being wrong)
        // degrades to an empty result rather than throwing.
        $hits = $response->json('data.search.results.hits');

        if (! is_array($hits)) {
            return [];
        }

        return collect($hits)
            ->map(fn (array $hit) => $hit['document'] ?? null)
            ->filter()
            ->map(fn (array $doc) => $this->mapSearchResultToCandidate($doc))
            ->all();
    }

    private function mapEditionToCandidate(array $edition, string $code): MetadataCandidate
    {
        $book = $edition['book'] ?? [];

        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: $code,
            attributes: [
                'title' => $book['title'] ?? null,
                'authors' => collect($book['contributions'] ?? [])->pluck('author.name')->filter()->implode(', '),
                'description' => $book['description'] ?? null,
                'publisher' => $edition['publisher']['name'] ?? null,
                'page_count' => $edition['pages'] ?? null,
                'language' => $edition['language']['language'] ?? null,
                'release_date' => $edition['release_date'] ?? null,
                'format' => $edition['physical_format'] ?? null,
                'isbn10' => $edition['isbn_10'] ?? null,
                'isbn13' => $edition['isbn_13'] ?? null,
                'ean' => $code,
            ],
            coverUrls: array_filter([$edition['image']['url'] ?? null]),
        );
    }

    /**
     * The Typesense-backed search only documents cover_color (an extracted
     * dominant color), not an actual cover image URL, for books — so unlike
     * mapEditionToCandidate() this never has a cover to offer.
     */
    private function mapSearchResultToCandidate(array $doc): MetadataCandidate
    {
        $isbns = collect($doc['isbns'] ?? []);

        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: (string) ($doc['slug'] ?? $doc['title'] ?? ''),
            attributes: [
                'title' => $doc['title'] ?? null,
                'authors' => implode(', ', $doc['author_names'] ?? []),
                'description' => $doc['description'] ?? null,
                'page_count' => $doc['pages'] ?? null,
                'release_date' => isset($doc['release_year']) ? "{$doc['release_year']}-01-01" : null,
                'isbn13' => $isbns->first(fn ($isbn) => strlen((string) $isbn) === 13),
                'isbn10' => $isbns->first(fn ($isbn) => strlen((string) $isbn) === 10),
            ],
            coverUrls: [],
        );
    }

    private function query(string $query, array $variables): ?Response
    {
        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            return null;
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'User-Agent' => 'MedInv (https://github.com/vulture20/MedInv)',
        ])->post(self::GRAPHQL_URL, ['query' => $query, 'variables' => $variables]);

        // Hasura (what Hardcover's API runs on) returns a 200 with an `errors`
        // array for a query-level failure (bad variable, depth limit, ...),
        // not necessarily a 4xx/5xx — successful() alone isn't enough.
        if (! $response->successful() || $response->json('errors')) {
            return null;
        }

        return $response;
    }

    /** Same runtime-configured-secret pattern as UpcMdbProvider::apiKey() — see that class's docblock. Strips a "Bearer " prefix defensively, see this class's docblock. */
    private function apiKey(): ?string
    {
        $config = MetadataPlugin::query()->where('provider_key', $this->key())->first()?->config;
        $key = $config['api_key'] ?? null;

        if ($key === null) {
            return null;
        }

        return preg_replace('/^Bearer\s+/i', '', trim($key));
    }
}
