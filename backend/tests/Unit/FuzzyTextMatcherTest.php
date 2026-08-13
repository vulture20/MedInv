<?php

namespace Tests\Unit;

use App\Domain\Search\FuzzyTextMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * GitHub issue #9: the `fuzzy` search flag used to only relax case
 * sensitivity — this covers the actual typo-tolerant matcher that replaced
 * the missing piece (App\Domain\Search\SearchService's portable, non-pgsql
 * fuzzy branch).
 */
class FuzzyTextMatcherTest extends TestCase
{
    #[DataProvider('matchingCases')]
    public function test_matches_all_words_as_expected(string $query, string $text, bool $expected): void
    {
        $this->assertSame($expected, FuzzyTextMatcher::matchesAllWords($query, $text));
    }

    public static function matchingCases(): array
    {
        return [
            'exact match' => ['Frankenstein', 'Frankenstein', true],
            'case-insensitive exact match' => ['FRANKENSTEIN', 'frankenstein', true],
            'case-insensitive with mixed case field' => ['müller', 'MÜLLER', true],

            // Plain substring/prefix matching must keep working exactly as
            // it does via SearchService's existing LIKE '%query%' today —
            // a short prefix like "frank" sits far outside any sane edit
            // distance from a longer word like "frankenstein", so this
            // only passes because of the substring check, not the fuzzy one.
            'substring prefix match' => ['frank', 'Frankenstein', true],
            'substring match mid-word' => ['stein', 'Frankenstein', true],
            'substring match across a multi-word field' => ['karloff', 'Boris Karloff', true],

            // A single typo within the length-scaled threshold.
            'single typo, long word (threshold 3)' => ['Frankenstien', 'Frankenstein', true],
            'single typo, short word (threshold 1)' => ['Karlof', 'Boris Karloff', true],
            'transposition typo' => ['Klaroff', 'Boris Karloff', true],

            // The umlaut/transliteration case: "ü" -> "ue" costs a
            // substitution plus an insertion (distance 2), which must fall
            // within the ≤8-length word's threshold of 2.
            'umlaut transliteration' => ['mueller', 'Müller', true],

            // Too many differences for the word's length bucket.
            'too many typos for a short word' => ['xz', 'CD', false],
            'unrelated word entirely' => ['xyz123', 'Frankenstein', false],
            'completely different long word' => ['automobile', 'Frankenstein', false],

            // A multi-word query requires every word to match something,
            // not just one of them.
            'multi-word query, all words match' => ['Boris Karlof', 'Boris Karloff', true],
            'multi-word query, one word does not match anything' => ['Boris Nonexistentword', 'Boris Karloff', false],

            'empty query never matches' => ['', 'Frankenstein', false],
            'blank query never matches' => ['   ', 'Frankenstein', false],
        ];
    }

    #[DataProvider('distanceCases')]
    public function test_distance_is_multibyte_safe(string $a, string $b, int $expected): void
    {
        $this->assertSame($expected, FuzzyTextMatcher::distance($a, $b));
    }

    public static function distanceCases(): array
    {
        return [
            'identical strings' => ['frankenstein', 'frankenstein', 0],
            'single substitution' => ['cat', 'bat', 1],
            'single insertion' => ['cat', 'cats', 1],
            'single deletion' => ['cats', 'cat', 1],
            'umlaut counted as one character, not two bytes' => ['müller', 'muller', 1],
            'umlaut transliteration' => ['müller', 'mueller', 2],
            'empty strings' => ['', '', 0],
            'one empty string' => ['', 'abc', 3],
        ];
    }
}
