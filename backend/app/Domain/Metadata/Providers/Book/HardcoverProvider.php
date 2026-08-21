<?php

namespace App\Domain\Metadata\Providers\Book;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderConfigField;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\Contracts\TestableMetadataProvider;
use App\Domain\Metadata\MetadataProviderRequestException;
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
 * Both lookupByCode() and search() were live-verified against the real API
 * (a real account's token, EAN 9780547928227 "The Hobbit", and a free-text
 * search for "the hobbit") — the response shapes below reflect what was
 * actually observed, not just what the docs describe. Two things live data
 * revealed that the documentation didn't:
 *  - search()'s per-document `image` field (a cover image object) isn't
 *    listed anywhere in Hardcover's documented Book search fields (only
 *    `cover_color`, an extracted color, is) — but it's genuinely present
 *    and populated in real responses, so it's used as this candidate's
 *    cover, same as lookupByCode()'s.
 *  - search()'s documents also carry a real `release_date` field directly
 *    (not just `release_year`), contrary to the documented field list
 *    (which only mentions `release_date_i`/`release_year`) — preferred
 *    over synthesizing one from `release_year` when present.
 *  - Both lookupByCode() and search() descriptions come back as raw HTML
 *    (e.g. `<p>...</p>`, `<i>...</i>`), not plain text — stripped via
 *    strip_tags() so it doesn't render as literal markup in the UI.
 * search()'s overall envelope shape ({hits: [{document: {...}}]},
 * Typesense's own well-known, engine-level convention) matched what was
 * inferred before live-testing; the is_array() guard is kept regardless as
 * cheap insurance against a future API change.
 *
 * lookupByCode() is built directly off Hardcover's own literal, official
 * example query ("Get Edition Details by ISBN" in their Editions schema
 * docs).
 *
 * testConfig() (GitHub issue #162) sends a minimal `{ me { id } }` query —
 * Hardcover's documented field for the currently authenticated user,
 * cheap enough to not resemble a real lookup at all. GitHub issue #162's
 * own text anticipated Hasura's usual "200 plus an `errors` array"
 * behavior (see query()'s own comment) applying to an invalid token too —
 * live-checked before implementing rather than assumed, since a genuine
 * token was never available to confirm it either way: a bogus token
 * actually gets rejected with a plain `401 {"error": "invalid_token",
 * "error_description": "Invalid JWT"}`, one layer in front of Hasura
 * entirely, not a Hasura-level GraphQL error. Simpler than the issue's
 * own guess, and confirmed live rather than left as a caveat.
 */
class HardcoverProvider implements MetadataProviderInterface, TestableMetadataProvider
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

    /** See MetadataProviderInterface::version()'s docblock (GitHub issue #44). */
    public function version(): string
    {
        return 'v1.0';
    }

    /** See MetadataProviderInterface::sourceType()'s docblock (GitHub issue #55) — a real, documented (GraphQL) API. */
    public function sourceType(): string
    {
        return 'api';
    }

    /** GitHub issue #158: a real, documented code-based lookup — see MetadataProviderInterface::supportsCodeLookup()'s own docblock. */
    public function supportsCodeLookup(): bool
    {
        return true;
    }

    /**
     * GitHub issue #162: see this class's own docblock for the live check
     * behind this — an invalid token is rejected with a plain `401`
     * before Hasura (Hardcover's GraphQL engine) ever sees the query, not
     * folded into the 200-plus-`errors` shape a genuine GraphQL-level
     * failure gets (query()'s own comment). `$config` is the
     * not-necessarily-saved-yet candidate value PluginsPage.tsx's "Test"
     * button sends, not metadata_plugins.config — stripBearerPrefix()
     * applied the same defensive way apiKey() already applies it to the
     * saved value, so a pasted "Bearer eyJ..." string tests the same way
     * it would actually be used once saved.
     */
    public function testConfig(array $config): bool
    {
        $token = $config['api_key'] ?? null;
        if (! is_string($token) || $token === '') {
            return false;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->stripBearerPrefix($token),
            'User-Agent' => 'MedInv (https://github.com/vulture20/MedInv)',
        ])->post(self::GRAPHQL_URL, ['query' => '{ me { id } }']);

        if ($response->status() === 401) {
            return false;
        }

        if ($response->successful() && ! $response->json('errors') && $response->json('data.me.id') !== null) {
            return true;
        }

        throw new MetadataProviderRequestException("Hardcover config test failed with status {$response->status()}.");
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

        // query() returns null both for a missing/invalid api_key and for an
        // actual request/GraphQL-level failure — either way the request
        // itself didn't succeed, distinct from "no edition for this ISBN"
        // below, reported as 'failed' rather than 'no_match' (GitHub issue
        // #53). search() deliberately keeps query()'s existing "return
        // null, don't throw" behavior — only this lookup-by-code path (the
        // one #53 is about) distinguishes the two.
        if ($response === null) {
            throw new MetadataProviderRequestException('Hardcover request failed (missing/invalid api_key or a GraphQL-level error).');
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

        // Confirmed live to be Typesense's standard {hits: [{document: {...}}]}
        // shape (see this class's docblock) — kept defensive anyway as cheap
        // insurance against a future API change.
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
                'description' => $this->plainText($book['description'] ?? null),
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

    private function mapSearchResultToCandidate(array $doc): MetadataCandidate
    {
        $isbns = collect($doc['isbns'] ?? []);

        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: (string) ($doc['id'] ?? $doc['slug'] ?? $doc['title'] ?? ''),
            attributes: [
                'title' => $doc['title'] ?? null,
                'authors' => implode(', ', $doc['author_names'] ?? []),
                'description' => $this->plainText($doc['description'] ?? null),
                'page_count' => $doc['pages'] ?? null,
                'release_date' => $doc['release_date'] ?? (isset($doc['release_year']) ? "{$doc['release_year']}-01-01" : null),
                'isbn13' => $isbns->first(fn ($isbn) => strlen((string) $isbn) === 13),
                'isbn10' => $isbns->first(fn ($isbn) => strlen((string) $isbn) === 10),
            ],
            // The `image` field isn't in Hardcover's documented Book search
            // fields list (only `cover_color`, an extracted color, is) — but
            // it's genuinely present and populated in real responses (see
            // this class's docblock), so it's used the same way
            // mapEditionToCandidate() uses lookupByCode()'s image.
            coverUrls: array_filter([$doc['image']['url'] ?? null]),
        );
    }

    /** Both lookupByCode() and search() return descriptions as raw HTML (confirmed live) — stripped so it doesn't render as literal markup. */
    private function plainText(?string $html): ?string
    {
        return $html === null ? null : trim(strip_tags($html));
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

        return $key === null ? null : $this->stripBearerPrefix($key);
    }

    /** GitHub issue #162: split out of apiKey() so testConfig() can apply the exact same defensive stripping to a not-yet-saved candidate value — see this class's own docblock for why a pasted token can already include this prefix. */
    private function stripBearerPrefix(string $token): string
    {
        return preg_replace('/^Bearer\s+/i', '', trim($token));
    }
}
