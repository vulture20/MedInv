<?php

namespace App\Domain\Metadata;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Models\Library;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates briefing 8.3: query every enabled provider for the
 * library's media type, merge their candidates into one list for the user
 * to pick from (or reject all). Duplicate-EAN rejection within the target
 * library (5.1) happens at record-creation time in the media controllers,
 * not here — this service only gathers candidates.
 */
class MetadataImportService
{
    public function __construct(private readonly MetadataProviderRegistry $registry) {}

    /** @return array<int, array> Flattened MetadataCandidate::toArray() results from all enabled providers. */
    public function lookup(Library $library, string $code): array
    {
        $candidates = [];

        foreach ($this->registry->enabledProvidersFor($library->media_type) as $provider) {
            try {
                foreach ($provider->lookupByCode($code) as $candidate) {
                    $candidates[] = $candidate;
                }
            } catch (\Throwable $e) {
                // A single failing provider must not block the others (8.3) — log and continue.
                Log::warning("Metadata provider {$provider->key()} failed for code {$code}: {$e->getMessage()}");
            }
        }

        return array_map(fn (MetadataCandidate $c) => $c->toArray(), $candidates);
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
}
