<?php

namespace App\Domain\Metadata;

/**
 * Sums a CD's track durations into a total runtime (GitHub issue #48) —
 * pure and stateless, like CidrMatcher/FuzzyTextMatcher/MetadataMerger
 * elsewhere in this domain.
 *
 * Only returns a total when *every* track's duration is known: neither
 * Discogs' nor MusicBrainz's track data reliably has a duration for every
 * single track (a blank/unlisted duration is common, confirmed live
 * against real releases) — summing only the known durations and silently
 * ignoring the unknown ones would produce a number that looks precise but
 * is actually a lower bound with no way to tell how far off it is. Better
 * to have no computed runtime at all than a confidently-wrong one.
 */
final class TrackListRuntimeCalculator
{
    /**
     * @param  array<int, array{duration_seconds: int|null}>  $tracks
     */
    public static function totalSeconds(array $tracks): ?int
    {
        if ($tracks === []) {
            return null;
        }

        $durations = array_column($tracks, 'duration_seconds');

        if (in_array(null, $durations, true)) {
            return null;
        }

        return array_sum($durations);
    }
}
