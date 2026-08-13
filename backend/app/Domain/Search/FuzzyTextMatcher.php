<?php

namespace App\Domain\Search;

/**
 * Word-level typo tolerance for SearchService's `fuzzy` flag (briefing 13.,
 * GitHub issue #9) — kept as its own pure, stateless class (no DB/framework
 * dependency) so the matching logic itself has direct unit-test coverage
 * rather than only being exercised indirectly through a live database
 * connection, same rationale as CidrMatcher.
 *
 * PHP's built-in levenshtein() operates byte-wise, not multibyte-safe — it
 * miscounts German umlauts (ä/ö/ü/ß), which matters for this app's
 * German-first UI, so distance() re-implements the classic DP algorithm over
 * mb_str_split() arrays instead.
 */
class FuzzyTextMatcher
{
    /**
     * True if every word in $query either appears as a plain substring of
     * $text, or has *some* word in $text within a length-scaled edit
     * distance. The substring check is deliberately kept alongside the
     * fuzzy one — SearchService already matches prefixes/partial words via
     * plain LIKE today (e.g. "frank" finding "Frankenstein"), and dropping
     * that in favor of pure Levenshtein matching would regress it, since a
     * short prefix like "frank" sits far outside any sane edit-distance
     * threshold against a longer word like "frankenstein".
     */
    public static function matchesAllWords(string $query, string $text): bool
    {
        $queryWords = self::tokenize($query);

        if ($queryWords === []) {
            return false;
        }

        $textLower = mb_strtolower($text);
        $textWords = self::tokenize($text);

        foreach ($queryWords as $queryWord) {
            if (mb_stripos($textLower, $queryWord) !== false) {
                continue;
            }

            if (! self::hasFuzzyMatch($queryWord, $textWords)) {
                return false;
            }
        }

        return true;
    }

    private static function hasFuzzyMatch(string $queryWord, array $textWords): bool
    {
        $threshold = self::threshold(mb_strlen($queryWord));

        foreach ($textWords as $textWord) {
            if (self::distance($queryWord, $textWord) <= $threshold) {
                return true;
            }
        }

        return false;
    }

    /** Splits on anything that isn't a letter (Unicode-aware, covers umlauts) or digit. */
    private static function tokenize(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Edit-distance tolerance scaled by the *query* word's length — not the
     * matched field word's, which can vary arbitrarily and would otherwise
     * let a short, unrelated query match wildly dissimilar long text.
     */
    private static function threshold(int $queryWordLength): int
    {
        return match (true) {
            $queryWordLength <= 4 => 1,
            $queryWordLength <= 8 => 2,
            default => 3,
        };
    }

    /** Classic single-row Levenshtein DP, multibyte-safe via mb_str_split(). */
    public static function distance(string $a, string $b): int
    {
        $a = mb_str_split(mb_strtolower($a));
        $b = mb_str_split(mb_strtolower($b));
        $lenA = count($a);
        $lenB = count($b);

        $row = range(0, $lenB);

        for ($i = 1; $i <= $lenA; $i++) {
            $previousDiagonal = $row[0];
            $row[0] = $i;

            for ($j = 1; $j <= $lenB; $j++) {
                $previous = $row[$j];
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $row[$j] = min($row[$j] + 1, $row[$j - 1] + 1, $previousDiagonal + $cost);
                $previousDiagonal = $previous;
            }
        }

        return $row[$lenB];
    }
}
