<?php

namespace App\Domain\Metadata;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Models\Library;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates briefing 8.3: query every enabled provider for the
 * library's media type, gather their candidates into one list. Duplicate-EAN
 * rejection within the target library (5.1) happens at record-creation time
 * in the media controllers, not here — this service only gathers candidates
 * (and, via lookupMerged(), the field-by-field comparison MetadataMerger
 * builds from them).
 *
 * GitHub issue #159: a provider that can never contribute through
 * lookupByCode() at all (`supportsCodeLookup() === false`, GitHub issue
 * #158 — today only TMDB) still gets a real chance to contribute to an
 * EAN-based lookup, as a second round: once the first (EAN) round's own
 * candidates agree on a title, that title is fed into such a provider's
 * search() instead. See collectCandidatesByCode()'s own docblock for the
 * two deliberate scoping decisions this makes (only when the first round's
 * title is unambiguous; only for providers that structurally have no other
 * way to ever contribute) and why. No change was needed to
 * MetadataCandidate or MetadataMerger for this — a second round's
 * candidates are ordinary MetadataCandidate objects merged the exact same
 * way as the first round's; the only new, additive piece of information is
 * each provider_statuses entry's `stage` ('code' | 'title'), which
 * PluginsPage.tsx's sibling, MetadataMergeReview.tsx's ProviderStatusList,
 * uses to label a title-round provider's contribution as "gefunden über
 * Titel" rather than leaving it looking indistinguishable from an ordinary
 * EAN match.
 */
class MetadataImportService
{
    public function __construct(
        private readonly MetadataProviderRegistry $registry,
        private readonly MetadataMerger $merger,
    ) {}

    /** @return array<int, array> Flattened MetadataCandidate::toArray() results from all enabled providers. */
    public function lookup(Library $library, string $code): array
    {
        return array_map(fn (MetadataCandidate $c) => $c->toArray(), $this->collectCandidatesByCode($library, $code)['candidates']);
    }

    /**
     * Same lookup as lookup() above, plus MetadataMerger's field-by-field
     * comparison across every candidate found (GitHub follow-up to 8.3: the
     * user picks per-field/per-cover instead of one whole provider record).
     * `candidates` is included alongside `merged` for the same reason
     * lookup() already returns it — attribution/traceability of which
     * provider actually said what — even though CapturePage.tsx's UI is
     * driven by `merged`, not this raw list.
     *
     * `provider_statuses` (GitHub issue #53) surfaces, per enabled provider,
     * whether its request actually succeeded rather than only logging a
     * failure server-side (Log::warning below) — without it, a user cannot
     * tell "this provider genuinely has no match" apart from "this
     * provider's request failed" (wrong API key, rate limit, a blocked
     * scraper like the Amazon ones from #50), both of which look identical
     * from the merged result alone.
     *
     * @return array{candidates: array<int, array>, merged: array, provider_statuses: array<int, array{provider_key: string, status: string, candidate_count: int, stage: string}>}
     */
    public function lookupMerged(Library $library, string $code): array
    {
        $result = $this->collectCandidatesByCode($library, $code);

        return [
            'candidates' => array_map(fn (MetadataCandidate $c) => $c->toArray(), $result['candidates']),
            'merged' => $this->merger->merge($result['candidates']),
            'provider_statuses' => $result['provider_statuses'],
        ];
    }

    /** @return array<int, array> */
    public function search(Library $library, string $query): array
    {
        $candidates = [];

        foreach ($this->registry->enabledProvidersFor($library->media_type) as $provider) {
            try {
                foreach ($provider->search($query) as $candidate) {
                    $candidates[] = $candidate;
                }
            } catch (\Throwable $e) {
                Log::warning("Metadata provider {$provider->key()} failed for query \"{$query}\": {$e->getMessage()}");
            }
        }

        return array_map(fn (MetadataCandidate $c) => $c->toArray(), $candidates);
    }

    /**
     * GitHub issue #159: round 1 (EAN) is unchanged in spirit — every
     * enabled provider that can meaningfully answer lookupByCode() gets
     * queried — but now actually skips a provider that never could
     * (`supportsCodeLookup() === false`) instead of calling it for a
     * guaranteed `[]` (the same pointless round-trip GitHub issue #157's
     * TmdbProvider::lookupByCode() itself already documents). Those
     * providers get queried in round 2 below instead, by title.
     *
     * Round 2 only runs, and only for exactly those skipped providers, when
     * two conditions both hold — two deliberate scoping decisions, not
     * incidental:
     *
     *  - Round 1's own candidates must *agree* on a title
     *    (resolveAgreedTitle() below). If EAN-capable providers disagree
     *    with each other about the title (e.g. a barcode shared by more
     *    than one real release — DiscogsProvider's own docblock documents
     *    this happening live), searching a title-only provider with
     *    *either* guess risks silently mixing in a completely unrelated
     *    film's data, which is worse than simply not enriching this
     *    lookup further. A provider that would otherwise have run gets a
     *    `skipped` provider_statuses entry instead, so it's still visible
     *    *why* it didn't contribute, the same transparency #53 already
     *    established for `failed` vs `no_match`.
     *  - Only providers with `supportsCodeLookup() === false` are eligible
     *    at all — not "every provider that happened to report no_match in
     *    round 1". Opening round 2 to every EAN-capable provider too would
     *    multiply the request count (and, for a paid API, the cost) of
     *    *every* unsuccessful EAN lookup across every provider, not just
     *    the one (TMDB) that structurally has no other way to ever
     *    contribute — a JPC/UPCMDB/etc. `no_match` in round 1 already *is*
     *    a genuine answer, not a reason to ask again a different way.
     *
     * @return array{candidates: MetadataCandidate[], provider_statuses: array<int, array{provider_key: string, status: string, candidate_count: int, stage: string}>}
     */
    private function collectCandidatesByCode(Library $library, string $code): array
    {
        $providers = $this->registry->enabledProvidersFor($library->media_type);
        $codeProviders = $providers->filter(fn (MetadataProviderInterface $p) => $p->supportsCodeLookup());
        $titleOnlyProviders = $providers->reject(fn (MetadataProviderInterface $p) => $p->supportsCodeLookup());

        [$candidates, $statuses] = $this->queryProviders($codeProviders, fn (MetadataProviderInterface $p) => $p->lookupByCode($code), "code {$code}", 'code');

        if ($titleOnlyProviders->isNotEmpty()) {
            $title = $this->resolveAgreedTitle($candidates);

            if ($title !== null) {
                [$titleCandidates, $titleStatuses] = $this->queryProviders($titleOnlyProviders, fn (MetadataProviderInterface $p) => $p->search($title), "title \"{$title}\" (round 2 for code {$code})", 'title');
                $candidates = [...$candidates, ...$titleCandidates];
                $statuses = [...$statuses, ...$titleStatuses];
            } else {
                foreach ($titleOnlyProviders as $provider) {
                    $statuses[] = ['provider_key' => $provider->key(), 'status' => 'skipped', 'candidate_count' => 0, 'stage' => 'title'];
                }
            }
        }

        return ['candidates' => $candidates, 'provider_statuses' => $statuses];
    }

    /**
     * Shared request/status-bookkeeping loop behind both rounds of
     * collectCandidatesByCode() above — identical shape to the single round
     * this replaced, just parameterized over which callable to invoke per
     * provider and which `stage` to stamp each status entry with, so a
     * consumer (MetadataMergeReview.tsx's ProviderStatusList) can tell a
     * round-2 contribution apart from an ordinary EAN match.
     *
     * @param  Collection<int, MetadataProviderInterface>  $providers
     * @param  callable(MetadataProviderInterface): MetadataCandidate[]  $query
     * @return array{0: MetadataCandidate[], 1: array<int, array{provider_key: string, status: string, candidate_count: int, stage: string}>}
     */
    private function queryProviders(Collection $providers, callable $query, string $logContext, string $stage): array
    {
        $candidates = [];
        $statuses = [];

        foreach ($providers as $provider) {
            try {
                $found = $query($provider);
                foreach ($found as $candidate) {
                    $candidates[] = $candidate;
                }
                $statuses[] = [
                    'provider_key' => $provider->key(),
                    'status' => count($found) > 0 ? 'ok' : 'no_match',
                    'candidate_count' => count($found),
                    'stage' => $stage,
                ];
            } catch (\Throwable $e) {
                // A single failing provider must not block the others (8.3) — log and continue.
                // The failure itself is still reported below, not just logged (#53) — this is
                // the only place that distinguishes "no match" from "the request itself failed".
                Log::warning("Metadata provider {$provider->key()} failed for {$logContext}: {$e->getMessage()}");
                $statuses[] = ['provider_key' => $provider->key(), 'status' => 'failed', 'candidate_count' => 0, 'stage' => $stage];
            }
        }

        return [$candidates, $statuses];
    }

    /**
     * The title every round-1 candidate that reported one agrees on
     * (MetadataMerger's own "agreed" concept — a single option once
     * duplicates/whitespace-only differences are normalized away), or null
     * when there's genuinely nothing to search a title-only provider with:
     * no round-1 candidates at all, none of them reported a title, or they
     * reported *different* titles (see collectCandidatesByCode()'s own
     * docblock for why that specific case deliberately skips round 2
     * rather than guessing one of the disagreeing values).
     *
     * @param  MetadataCandidate[]  $candidates
     */
    private function resolveAgreedTitle(array $candidates): ?string
    {
        if ($candidates === []) {
            return null;
        }

        $titleField = $this->merger->merge($candidates)['fields']['title'] ?? null;

        if (! $titleField || ! $titleField['agreed']) {
            return null;
        }

        $title = $titleField['value'];

        return is_string($title) && $title !== '' ? $title : null;
    }
}
