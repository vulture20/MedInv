<?php

namespace Tests\Unit;

use App\Domain\Metadata\Contracts\MetadataCandidate;
use App\Domain\Metadata\MetadataMerger;
use Tests\TestCase;

/**
 * Pure unit coverage for the field-by-field merge logic (see
 * MetadataMerger's docblock for the feature this implements). Deliberately
 * built with hand-crafted MetadataCandidate objects rather than real
 * provider HTTP fakes — the merge algorithm itself has no I/O to mock;
 * BulkImportServiceTest separately covers this being wired up end-to-end
 * through the real capture endpoint.
 */
class MetadataMergerTest extends TestCase
{
    private function candidate(string $providerKey, array $attributes, array $coverUrls = []): MetadataCandidate
    {
        return new MetadataCandidate(providerKey: $providerKey, sourceId: '1', attributes: $attributes, coverUrls: $coverUrls);
    }

    public function test_a_field_every_provider_agrees_on_is_merged_automatically(): void
    {
        $result = (new MetadataMerger)->merge([
            $this->candidate('a', ['title' => 'Dune']),
            $this->candidate('b', ['title' => 'Dune']),
        ]);

        $this->assertSame(['value' => 'Dune', 'agreed' => true, 'options' => [
            ['value' => 'Dune', 'provider_keys' => ['a', 'b']],
        ]], $result['fields']['title']);
    }

    public function test_a_field_only_one_provider_reported_is_merged_as_agreed(): void
    {
        $result = (new MetadataMerger)->merge([
            $this->candidate('a', ['title' => 'Dune', 'publisher' => 'Ace Books']),
            $this->candidate('b', ['title' => 'Dune']),
        ]);

        $this->assertTrue($result['fields']['publisher']['agreed']);
        $this->assertSame('Ace Books', $result['fields']['publisher']['value']);
        $this->assertSame([['value' => 'Ace Books', 'provider_keys' => ['a']]], $result['fields']['publisher']['options']);
    }

    public function test_a_field_providers_disagree_on_is_offered_as_separate_options(): void
    {
        $result = (new MetadataMerger)->merge([
            $this->candidate('musicbrainz', ['title' => 'OK Computer']),
            $this->candidate('discogs', ['title' => 'OK Computer (Collector\'s Edition)']),
        ]);

        $field = $result['fields']['title'];
        $this->assertFalse($field['agreed']);
        $this->assertNull($field['value']);
        $this->assertSame([
            ['value' => 'OK Computer', 'provider_keys' => ['musicbrainz']],
            ['value' => 'OK Computer (Collector\'s Edition)', 'provider_keys' => ['discogs']],
        ], $field['options']);
    }

    public function test_a_field_no_provider_reported_a_usable_value_for_is_omitted_entirely(): void
    {
        $result = (new MetadataMerger)->merge([
            $this->candidate('a', ['title' => 'Dune', 'publisher' => null]),
            $this->candidate('b', ['title' => 'Dune', 'publisher' => '']),
        ]);

        $this->assertArrayNotHasKey('publisher', $result['fields']);
    }

    /** An int `1` from one provider and a string `"1"` from another are the same fact, not two options to choose between. */
    public function test_values_that_only_differ_in_type_are_treated_as_the_same_value(): void
    {
        $result = (new MetadataMerger)->merge([
            $this->candidate('a', ['disc_count' => 1]),
            $this->candidate('b', ['disc_count' => '1']),
        ]);

        $this->assertTrue($result['fields']['disc_count']['agreed']);
        $this->assertSame(1, $result['fields']['disc_count']['value']);
    }

    public function test_values_that_only_differ_in_surrounding_whitespace_are_treated_as_the_same_value(): void
    {
        $result = (new MetadataMerger)->merge([
            $this->candidate('a', ['title' => 'Dune']),
            $this->candidate('b', ['title' => '  Dune  ']),
        ]);

        $this->assertTrue($result['fields']['title']['agreed']);
        $this->assertSame('Dune', $result['fields']['title']['value']);
    }

    /** A field a single provider reports on more than one of its own candidates (e.g. MusicBrainz returning several matching releases) lists that provider only once per distinct value, not once per candidate. */
    public function test_the_same_provider_contributing_more_than_one_candidate_does_not_duplicate_its_key_in_a_group(): void
    {
        $result = (new MetadataMerger)->merge([
            $this->candidate('musicbrainz', ['title' => 'OK Computer']),
            $this->candidate('musicbrainz', ['title' => 'OK Computer']),
        ]);

        $this->assertSame(['musicbrainz'], $result['fields']['title']['options'][0]['provider_keys']);
    }

    public function test_zero_is_kept_as_a_legitimate_value_not_treated_as_empty(): void
    {
        $result = (new MetadataMerger)->merge([
            $this->candidate('a', ['disc_count' => 0]),
        ]);

        $this->assertTrue($result['fields']['disc_count']['agreed']);
        $this->assertSame(0, $result['fields']['disc_count']['value']);
    }

    public function test_no_candidates_produces_no_fields_and_no_covers(): void
    {
        $result = (new MetadataMerger)->merge([]);

        $this->assertSame([], $result['fields']);
        $this->assertSame([], $result['covers']);
    }

    public function test_covers_are_pooled_from_every_candidate_and_tagged_with_their_provider(): void
    {
        $result = (new MetadataMerger)->merge([
            $this->candidate('discogs', [], ['https://i.discogs.com/a.jpg', 'https://i.discogs.com/b.jpg']),
            $this->candidate('musicbrainz', [], ['https://coverartarchive.org/c.jpg']),
        ]);

        $this->assertSame([
            ['url' => 'https://i.discogs.com/a.jpg', 'provider_key' => 'discogs'],
            ['url' => 'https://i.discogs.com/b.jpg', 'provider_key' => 'discogs'],
            ['url' => 'https://coverartarchive.org/c.jpg', 'provider_key' => 'musicbrainz'],
        ], $result['covers']);
    }

    public function test_the_exact_same_cover_url_reported_twice_is_only_offered_once(): void
    {
        $result = (new MetadataMerger)->merge([
            $this->candidate('discogs', [], ['https://i.discogs.com/a.jpg']),
            $this->candidate('somewhere-else', [], ['https://i.discogs.com/a.jpg']),
        ]);

        $this->assertCount(1, $result['covers']);
        $this->assertSame('discogs', $result['covers'][0]['provider_key']);
    }

    public function test_a_provider_with_no_covers_at_all_contributes_nothing_to_the_pool(): void
    {
        $result = (new MetadataMerger)->merge([
            $this->candidate('musicbrainz', ['title' => 'OK Computer']),
        ]);

        $this->assertSame([], $result['covers']);
    }
}
