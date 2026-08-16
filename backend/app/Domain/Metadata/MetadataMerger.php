<?php

namespace App\Domain\Metadata;

use App\Domain\Metadata\Contracts\MetadataCandidate;

/**
 * Merges every enabled provider's candidates for one lookup/search into a
 * single field-by-field comparison, refining briefing 8.3 (steps 3-5) per
 * explicit user instruction: rather than the user picking one whole
 * provider record wholesale and then a cover from within it, every
 * attribute is merged automatically wherever the providers that reported
 * it agree, and offered as separate, individually selectable options
 * wherever they genuinely differ — the same treatment applies to cover
 * images, pooled across every candidate rather than scoped to whichever
 * one record would otherwise have been chosen.
 *
 * Pure and stateless, like CidrMatcher/FuzzyTextMatcher elsewhere in this
 * codebase — no I/O, no DB, so the actual merge logic is covered by fast
 * unit tests (MetadataMergerTest) rather than needing HTTP fakes of real
 * providers; MetadataImportServiceTest/BulkImportServiceTest separately
 * cover this being wired up correctly end-to-end.
 */
final class MetadataMerger
{
    /**
     * @param  MetadataCandidate[]  $candidates
     * @return array{
     *     fields: array<string, array{value: mixed, agreed: bool, options: array<int, array{value: mixed, provider_keys: string[]}>}>,
     *     covers: array<int, array{url: string, provider_key: string}>,
     * }
     */
    public function merge(array $candidates): array
    {
        return [
            'fields' => $this->mergeFields($candidates),
            'covers' => $this->mergeCovers($candidates),
        ];
    }

    /** @param  MetadataCandidate[]  $candidates */
    private function mergeFields(array $candidates): array
    {
        $keys = collect($candidates)
            ->flatMap(fn (MetadataCandidate $c) => array_keys($c->attributes))
            ->unique();

        $fields = [];

        foreach ($keys as $key) {
            $field = $this->mergeField($candidates, $key);

            // A key no provider actually reported a usable value for (every
            // candidate had it null/absent/empty) is omitted entirely rather
            // than included as an empty/undecided field — there is nothing
            // to merge or choose between, and the frontend's normal
            // create-form default (blank) already covers this case exactly
            // like a provider that never mentioned the field at all.
            if ($field !== null) {
                $fields[$key] = $field;
            }
        }

        return $fields;
    }

    /** @param  MetadataCandidate[]  $candidates */
    private function mergeField(array $candidates, string $key): ?array
    {
        // Grouped by a normalized (trimmed, stringified) form so an int `1`
        // from one provider and a string `"1"` from another — or two values
        // that only differ in surrounding whitespace — collapse into the
        // same option instead of manufacturing a pointless choice between
        // two representations of the exact same fact.
        $groups = [];

        foreach ($candidates as $candidate) {
            $value = $candidate->attributes[$key] ?? null;

            if ($value === null) {
                continue;
            }
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === '') {
                continue;
            }

            $normalized = trim((string) $value);
            $groups[$normalized] ??= ['value' => $value, 'provider_keys' => []];
            $groups[$normalized]['provider_keys'][] = $candidate->providerKey;
        }

        if ($groups === []) {
            return null;
        }

        foreach ($groups as &$group) {
            // The same provider can contribute more than one candidate (e.g.
            // MusicBrainz's lookupByCode() returns one per matching release)
            // — dedupe so a field both of that provider's own releases agree
            // on doesn't list it twice.
            $group['provider_keys'] = array_values(array_unique($group['provider_keys']));
        }
        unset($group);

        $options = array_values($groups);
        $agreed = count($options) === 1;

        return [
            'value' => $agreed ? $options[0]['value'] : null,
            'agreed' => $agreed,
            'options' => $options,
        ];
    }

    /**
     * @param  MetadataCandidate[]  $candidates
     * @return array<int, array{url: string, provider_key: string}>
     */
    private function mergeCovers(array $candidates): array
    {
        $covers = [];
        $seenUrls = [];

        foreach ($candidates as $candidate) {
            foreach ($candidate->coverUrls as $url) {
                $url = trim($url);

                // The exact same image URL reported by more than one
                // candidate (or twice by the same provider) is one option,
                // not a duplicate entry — attributed to whichever candidate
                // reported it first.
                if ($url === '' || isset($seenUrls[$url])) {
                    continue;
                }
                $seenUrls[$url] = true;

                $covers[] = ['url' => $url, 'provider_key' => $candidate->providerKey];
            }
        }

        return $covers;
    }
}
