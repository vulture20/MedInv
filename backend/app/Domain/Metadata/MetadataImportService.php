<?php

namespace App\Domain\Metadata;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Models\Library;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates briefing 8.3: query every enabled provider for the
 * library's media type, gather their candidates into one list. Duplicate-EAN
 * rejection within the target library (5.1) happens at record-creation time
 * in the media controllers, not here — this service only gathers candidates
 * (and, via lookupMerged(), the field-by-field comparison MetadataMerger
 * builds from them).
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
     * @return array{candidates: array<int, array>, merged: array, provider_statuses: array<int, array{provider_key: string, status: string, candidate_count: int}>}
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
     * @return array{candidates: MetadataCandidate[], provider_statuses: array<int, array{provider_key: string, status: string, candidate_count: int}>}
     */
    private function collectCandidatesByCode(Library $library, string $code): array
    {
        $candidates = [];
        $statuses = [];

        foreach ($this->registry->enabledProvidersFor($library->media_type) as $provider) {
            try {
                $found = $provider->lookupByCode($code);
                foreach ($found as $candidate) {
                    $candidates[] = $candidate;
                }
                $statuses[] = [
                    'provider_key' => $provider->key(),
                    'status' => count($found) > 0 ? 'ok' : 'no_match',
                    'candidate_count' => count($found),
                ];
            } catch (\Throwable $e) {
                // A single failing provider must not block the others (8.3) — log and continue.
                // The failure itself is still reported below, not just logged (#53) — this is
                // the only place that distinguishes "no match" from "the request itself failed".
                Log::warning("Metadata provider {$provider->key()} failed for code {$code}: {$e->getMessage()}");
                $statuses[] = ['provider_key' => $provider->key(), 'status' => 'failed', 'candidate_count' => 0];
            }
        }

        return ['candidates' => $candidates, 'provider_statuses' => $statuses];
    }
}
