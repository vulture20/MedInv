<?php

namespace App\Domain\Metadata\Providers\UpcItemDb;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\MetadataProviderRequestException;
use Illuminate\Support\Facades\Http;

/**
 * Shared HTTP + mapping for the three UpcItemDb providers (UpcItemDbBook/
 * UpcItemDbCd/UpcItemDbDvdBlurayProvider — GitHub issue #192, following a
 * requested feasibility study). Deliberately narrow, last-resort role,
 * distinct from every other provider in this app: upcitemdb.com is a
 * generic, crowd-/retailer-sourced consumer-goods barcode database, not a
 * media-specific source — App\Domain\Metadata\Contracts\
 * NameOnlyFallbackProvider (implemented by all three concrete classes
 * alongside this trait) is what makes MetadataImportService::
 * collectCandidatesByCode() only ever call this as a last resort, when
 * every ordinary EAN-capable provider for the media type already found
 * nothing at all — its real job is supplying *a* title so GitHub issue
 * #159's round 2 (title-based search across the media-specific providers)
 * has something to work with, instead of round 1 dead-ending completely.
 *
 * Live-confirmed during #192's own feasibility study to require no
 * registration/API key at all for its "trial" tier
 * (`api.upcitemdb.com/prod/trial/lookup`) — 100 combined lookup+search
 * requests/day, 6/minute burst (per the endpoint's own documented rate
 * limits, exposed live via its `X-RateLimit-*` response headers) —
 * comfortably enough for a fallback that only ever fires when nothing else
 * already answered. `configFields()` is therefore empty; there is no
 * credential for an admin to configure.
 *
 * Not the same integration UpcMdbProvider's own docblock describes as a
 * past mistake ("Correct UPCitemdb to UPCMDB and implement the real API",
 * git commit 5654574) — that was upcitemdb.com wired up under UPCMDB's
 * provider key by confusing the two similarly-named but otherwise unrelated
 * services. This is a deliberate, new, and much narrower reintroduction of
 * the real upcitemdb.com under its own `*.upcitemdb` provider keys.
 *
 * A real live lookup during #192's feasibility study for ISBN-13
 * 9780747532699 (Harry Potter and the Philosopher's Stone) came back with a
 * correct `title` — but a `publisher` field that actually held the
 * *author's* name ("J. K. Rowling"), not a publisher, a concrete, confirmed
 * example of this database's uneven crowd-sourced data quality.
 * mapToCandidate() below deliberately maps only `title` (plus, where
 * present, a cover image) — never `description`, `publisher`/`vendor`,
 * `price`, or any other structured field this provider's raw response
 * happens to carry, even though upcitemdb.com's own API does return them —
 * this provider isn't meant to be picked by a user as a normal, full
 * metadata result, only to unlock round 2 for the providers that are.
 *
 * `code: "INVALID_UPC"` (confirmed live, both for a garbage input string
 * and for a syntactically-13-digit-but-checksum-invalid number) is
 * upcitemdb.com's own way of rejecting a code it won't even attempt to look
 * up — treated the same as a genuine `total: 0` no-match, not a request
 * failure, since MedInv only ever calls this with a code a user already
 * scanned/entered for this same lookup. Any other non-2xx status (e.g. the
 * free trial tier's rate limit, confirmed to exist via its documented
 * response headers but never actually triggered/observed during
 * development) is treated as a genuine request failure (GitHub issue #53),
 * not folded into "no match".
 */
trait UpcItemDbLookup
{
    private const BASE_URL = 'https://api.upcitemdb.com/prod/trial';

    public function configFields(): array
    {
        return [];
    }

    /** See MetadataProviderInterface::version()'s docblock — Beta: the data-quality caveats above, never live-tested against more than a handful of real codes. */
    public function version(): string
    {
        return 'v0.1-beta';
    }

    /** See MetadataProviderInterface::sourceType()'s docblock — a real, documented API (the free "trial" tier), not scraping. */
    public function sourceType(): string
    {
        return 'api';
    }

    /** GitHub issue #158: upcitemdb.com genuinely supports code-based lookup — see this trait's own docblock for the narrower, last-resort role that plays here regardless (NameOnlyFallbackProvider). */
    public function supportsCodeLookup(): bool
    {
        return true;
    }

    public function lookupByCode(string $code): array
    {
        $response = Http::timeout(10)->get(self::BASE_URL.'/lookup', ['upc' => $code]);

        if ($response->failed()) {
            throw new MetadataProviderRequestException("UPCitemdb request failed with status {$response->status()}.");
        }

        // Confirmed live — see this trait's own docblock: upcitemdb.com's
        // own way of saying "not a code we recognize" is a 200 response
        // with this specific `code`, not a non-2xx status.
        if ($response->json('code') === 'INVALID_UPC') {
            return [];
        }

        return collect($response->json('items', []))
            ->map(fn (array $item) => $this->mapToCandidate($item))
            ->all();
    }

    /**
     * upcitemdb.com's trial tier also documents a free-text search endpoint
     * (part of the same 100/day quota, with its own 40/day sub-limit) —
     * implemented for MetadataProviderInterface completeness and so
     * CapturePage.tsx's ordinary free-text "ohne EAN erfassen" flow (GitHub
     * issue #151, which queries every enabled provider unconditionally)
     * still gets a usable result from this provider too. This provider's
     * actual intended role, per this trait's own docblock, is entirely the
     * lookupByCode() fallback above; the `/search` endpoint itself was not
     * live-tested during #192's feasibility study.
     */
    public function search(string $query): array
    {
        $response = Http::timeout(10)->get(self::BASE_URL.'/search', ['s' => $query]);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json('items', []))
            ->map(fn (array $item) => $this->mapToCandidate($item))
            ->all();
    }

    /** See this trait's own docblock for why only `title` (+ a cover, where present) is mapped — never `description`/`publisher`/price/etc., despite the raw response carrying them. */
    private function mapToCandidate(array $item): MetadataCandidate
    {
        $images = $item['images'] ?? [];

        return new MetadataCandidate(
            providerKey: $this->key(),
            sourceId: (string) ($item['ean'] ?? $item['upc'] ?? ''),
            attributes: [
                'title' => $item['title'] ?? null,
            ],
            coverUrls: is_array($images) && $images !== [] ? [(string) $images[0]] : [],
        );
    }
}
