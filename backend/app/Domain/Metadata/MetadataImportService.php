<?php

namespace App\Domain\Metadata;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\Contracts\NameOnlyFallbackProvider;
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
 * EAN-based lookup, as a second round: the first (EAN) round's own
 * candidates' title(s) are fed into such a provider's search() instead.
 * See collectCandidatesByCode()'s own docblock for the deliberate scoping
 * decision this still makes (only for providers that structurally have no
 * other way to ever contribute) and why. No change was needed to
 * MetadataCandidate or MetadataMerger for this — a second round's
 * candidates are ordinary MetadataCandidate objects merged the exact same
 * way as the first round's; the only new, additive piece of information is
 * each provider_statuses entry's `stage` ('code' | 'title'), which
 * PluginsPage.tsx's sibling, MetadataMergeReview.tsx's ProviderStatusList,
 * uses to label a title-round provider's contribution as "gefunden über
 * Titel" rather than leaving it looking indistinguishable from an ordinary
 * EAN match.
 *
 * The first version of this only ever tried a single title — the one
 * every round-1 candidate agreed on — and skipped round 2 outright the
 * moment EAN-capable providers disagreed with each other, on the
 * reasoning that guessing wrong risked silently mixing in a completely
 * unrelated film's data. The user asked for that traded away too much:
 * even a disagreement usually still has the right title among the
 * top few most-supported options (e.g. a barcode shared by more than one
 * real release — DiscogsProvider's own docblock documents this happening
 * live — still has the *correct* release's title as one of the
 * candidates, just not the only one). resolveCandidateTitles() below now
 * always tries up to MAX_TITLE_CANDIDATES titles, ranked by how many
 * round-1 providers actually reported each one — a single title when
 * everyone agrees (the previous behavior, unchanged in that case), up to
 * three when they don't, rather than an all-or-nothing skip.
 *
 * GitHub issue #192: a provider implementing NameOnlyFallbackProvider
 * (today, upcitemdb.com's three media-type variants) is held back from
 * round 1 entirely and only queried, as an explicit extra round, when
 * round 1's ordinary code-capable providers found literally nothing — its
 * result is folded into the same candidate list as everything else (so its
 * title can still seed round 2 below), but always stamped with its own
 * `stage: 'fallback'` so it's never confused with a genuine media-specific
 * match. See collectCandidatesByCode()'s own docblock for the exact
 * gating.
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
     * How many of round 1's most-supported, distinct title candidates
     * round 2 (below) will try — explicit user request after using this
     * feature: a full skip on any EAN-provider disagreement (this class's
     * own history, see the class docblock) gave up too easily, since the
     * correct title is usually still among the top few even when not
     * every provider agrees on it.
     */
    private const MAX_TITLE_CANDIDATES = 3;

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
     * round 1's own candidates reported at least one usable title
     * (resolveCandidateTitles() below) — still a deliberate scoping
     * decision, just a narrower one than this class used to make (see its
     * own docblock for the "used to skip entirely on disagreement, no
     * longer does" history): only providers with `supportsCodeLookup() ===
     * false` are eligible for round 2 at all, not "every provider that
     * happened to report no_match in round 1". Opening round 2 to every
     * EAN-capable provider too would multiply the request count (and, for
     * a paid API, the cost) of *every* unsuccessful EAN lookup across
     * every provider, not just the one (TMDB) that structurally has no
     * other way to ever contribute — a JPC/UPCMDB/etc. `no_match` in round
     * 1 already *is* a genuine answer, not a reason to ask again a
     * different way. A provider with genuinely nothing to search with
     * (round 1 found no candidates at all, or none reported a title) gets
     * a `skipped` provider_statuses entry instead, so it's still visible
     * *why* it didn't contribute, the same transparency #53 already
     * established for `failed` vs `no_match`.
     *
     * GitHub issue #192: a NameOnlyFallbackProvider is set aside before
     * round 1 even runs and only queried afterwards, as its own explicit
     * round (reusing queryProviders() below with `stage: 'fallback'`),
     * when round 1's *ordinary* code-capable providers found nothing at
     * all — deliberately gated on "round 1 found zero candidates", not
     * "round 1 found no title" (which resolveCandidateTitles() already
     * covers, separately, for round 2): a code provider that found a
     * candidate without a title is still a genuine answer this app
     * shouldn't second-guess by also asking a generic fallback database.
     *
     * @return array{candidates: MetadataCandidate[], provider_statuses: array<int, array{provider_key: string, status: string, candidate_count: int, stage: string}>}
     */
    private function collectCandidatesByCode(Library $library, string $code): array
    {
        $providers = $this->registry->enabledProvidersFor($library->media_type);
        $fallbackProviders = $providers->filter(fn (MetadataProviderInterface $p) => $p instanceof NameOnlyFallbackProvider);
        $providers = $providers->reject(fn (MetadataProviderInterface $p) => $p instanceof NameOnlyFallbackProvider);
        $codeProviders = $providers->filter(fn (MetadataProviderInterface $p) => $p->supportsCodeLookup());
        $titleOnlyProviders = $providers->reject(fn (MetadataProviderInterface $p) => $p->supportsCodeLookup());

        [$candidates, $statuses] = $this->queryProviders($codeProviders, fn (MetadataProviderInterface $p) => $p->lookupByCode($code), "code {$code}", 'code');

        if ($candidates === [] && $fallbackProviders->isNotEmpty()) {
            [$fallbackCandidates, $fallbackStatuses] = $this->queryProviders($fallbackProviders, fn (MetadataProviderInterface $p) => $p->lookupByCode($code), "code {$code} (fallback)", 'fallback');
            $candidates = [...$candidates, ...$fallbackCandidates];
            $statuses = [...$statuses, ...$fallbackStatuses];
        }

        if ($titleOnlyProviders->isNotEmpty()) {
            $titles = $this->resolveCandidateTitles($candidates);

            if ($titles !== []) {
                [$titleCandidates, $titleStatuses] = $this->queryProvidersByTitles($titleOnlyProviders, $titles, $code);
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
     * Round 1's own request/status-bookkeeping loop — one query per
     * provider, one status entry each. `$stage` is still an explicit
     * parameter (rather than hardcoding `'code'` here) purely so this
     * stays a general single-query-per-provider helper on its own terms;
     * round 2 doesn't reuse it (see queryProvidersByTitles() below for why
     * trying up to MAX_TITLE_CANDIDATES titles per provider needs its own,
     * slightly different aggregation instead).
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
     * Up to MAX_TITLE_CANDIDATES distinct titles round 1's own candidates
     * reported, most-supported first. Reuses MetadataMerger's own
     * per-field `options` (each already grouped by normalized value, with
     * `provider_keys` deduped per GitHub follow-up to 8.3's own existing
     * behavior) rather than needing any new merge logic — when every
     * round-1 candidate that reported a title agrees, `options` has
     * exactly one entry and this returns exactly that one title, the same
     * single-title behavior this class always had; when they disagree,
     * `options` has more than one, sorted here by how many providers
     * support each so the top MAX_TITLE_CANDIDATES are the most-likely-
     * correct ones, not just whichever happened to be encountered first.
     * Empty when there's genuinely nothing to search a title-only provider
     * with: no round-1 candidates at all, or none of them reported a
     * title.
     *
     * @param  MetadataCandidate[]  $candidates
     * @return string[]
     */
    private function resolveCandidateTitles(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $titleField = $this->merger->merge($candidates)['fields']['title'] ?? null;

        if (! $titleField) {
            return [];
        }

        return collect($titleField['options'])
            ->sortByDesc(fn (array $option) => count($option['provider_keys']))
            ->pluck('value')
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->take(self::MAX_TITLE_CANDIDATES)
            ->values()
            ->all();
    }

    /**
     * Round 2's own query loop — deliberately not queryProviders() above,
     * since a title-only provider is now tried against up to
     * MAX_TITLE_CANDIDATES titles (not just one), and those attempts need
     * to collapse into a single provider_statuses entry per provider, not
     * one per title tried. A provider that finds *anything* across any of
     * its title attempts is reported 'ok' with the combined candidate
     * count; one whose every attempt genuinely succeeded with zero results
     * is 'no_match'; one where every attempt failed outright is 'failed' —
     * the same three-way distinction queryProviders() draws for a single
     * query, just aggregated across more than one attempt. A provider
     * whose *some* attempts failed and others succeeded (even with zero
     * results) is not reported 'failed' — a real answer for at least one
     * of the tried titles is more informative than the fact that another
     * one errored, and the failure is still logged either way.
     *
     * MetadataMerger's own dedup already keeps two attempts that happen to
     * surface the exact same real title (e.g. two of the tried titles
     * both resolving to the same actual film) from showing up as two
     * separate options — see mergeField()'s "same provider contributing
     * more than one candidate" handling — so nothing extra is needed here
     * for that.
     *
     * @param  Collection<int, MetadataProviderInterface>  $providers
     * @param  string[]  $titles
     * @return array{0: MetadataCandidate[], 1: array<int, array{provider_key: string, status: string, candidate_count: int, stage: string}>}
     */
    private function queryProvidersByTitles(Collection $providers, array $titles, string $code): array
    {
        $candidates = [];
        $statuses = [];

        foreach ($providers as $provider) {
            $found = [];
            $anySucceeded = false;

            foreach ($titles as $title) {
                try {
                    $found = [...$found, ...$provider->search($title)];
                    $anySucceeded = true;
                } catch (\Throwable $e) {
                    Log::warning("Metadata provider {$provider->key()} failed for title \"{$title}\" (round 2 for code {$code}): {$e->getMessage()}");
                }
            }

            $candidates = [...$candidates, ...$found];
            $statuses[] = [
                'provider_key' => $provider->key(),
                'status' => $found !== [] ? 'ok' : ($anySucceeded ? 'no_match' : 'failed'),
                'candidate_count' => count($found),
                'stage' => 'title',
            ];
        }

        return [$candidates, $statuses];
    }
}
