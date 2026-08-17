<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaCd;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GitHub issue #57 first added MediaCd::tracks (a JSON array of {position,
 * title, duration_seconds}, added by #48) to search — a query matching
 * only a track's title (not the album title itself) found nothing before
 * that. #57's own fix was deliberately coarse: it matched the whole JSON
 * blob as text, so a query matching a position number, a duration_seconds
 * value, or plain JSON punctuation elsewhere in the blob could also
 * produce a hit, not just a real track title. Issue #72 replaced that with
 * a precise match against tracks[].title specifically — this file covers
 * both the original "track title is findable at all" behavior and #72's
 * false-positive fix.
 */
class TrackListingSearchTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'admin'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function createCdLibraryWithTracks(User $owner): Library
    {
        $library = Library::query()->create([
            'name' => 'Albums',
            'media_type' => 'cd',
            'owner_id' => $owner->id,
        ]);

        MediaCd::query()->create([
            'library_id' => $library->id,
            'title' => 'OK Computer',
            'artist' => 'Radiohead',
            'ean' => '1234567890123',
            'tracks' => [
                ['position' => '1', 'title' => 'Airbag', 'duration_seconds' => 284],
                ['position' => '2', 'title' => 'Paranoid Android', 'duration_seconds' => 383],
            ],
        ]);

        return $library;
    }

    public function test_a_query_matching_only_a_track_title_finds_the_cd(): void
    {
        $user = $this->actingAsUser();
        $this->createCdLibraryWithTracks($user);

        // "Paranoid Android" appears only inside a track title, not in the
        // album title/artist/description — this is the gap #57 reported.
        $response = $this->getJson('/api/search?query=Paranoid Android&fuzzy=false');

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame('OK Computer', $response->json('0.title'));
    }

    public function test_a_typo_in_a_track_title_matches_with_fuzzy_true(): void
    {
        $user = $this->actingAsUser();
        $this->createCdLibraryWithTracks($user);

        $response = $this->getJson('/api/search?query=Airbagg&fuzzy=true');

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame('OK Computer', $response->json('0.title'));
    }

    public function test_the_same_track_title_typo_finds_nothing_with_fuzzy_false(): void
    {
        $user = $this->actingAsUser();
        $this->createCdLibraryWithTracks($user);

        $response = $this->getJson('/api/search?query=Airbagg&fuzzy=false');

        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    public function test_a_track_title_match_in_an_unshared_library_is_not_found(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->createCdLibraryWithTracks($owner);

        $this->actingAsUser('user');

        $response = $this->getJson('/api/search?query=Airbag&fuzzy=false');

        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    public function test_a_cd_with_no_tracks_recorded_still_appears_in_other_search_results(): void
    {
        $user = $this->actingAsUser();
        $library = Library::query()->create([
            'name' => 'Albums',
            'media_type' => 'cd',
            'owner_id' => $user->id,
        ]);
        MediaCd::query()->create([
            'library_id' => $library->id,
            'title' => 'Kid A',
            'artist' => 'Radiohead',
            'ean' => '9876543210123',
        ]);

        $response = $this->getJson('/api/search?query=Kid A&fuzzy=false');

        $response->assertOk();
        $this->assertCount(1, $response->json());
    }

    /**
     * The exact false positive #72 fixed: "284" is Airbag's duration_seconds
     * value, not a track title, and doesn't appear anywhere else on this CD
     * (title/artist/EAN) — #57's whole-JSON-blob-text match would have found
     * it anyway (LIKE '%284%' against the raw JSON string matches the
     * digits regardless of which JSON key they belong to); the field-
     * specific tracks[].title match added by #72 must not.
     */
    public function test_a_duration_value_does_not_produce_a_false_positive_match(): void
    {
        $user = $this->actingAsUser();
        $this->createCdLibraryWithTracks($user);

        $response = $this->getJson('/api/search?query=284&fuzzy=false');

        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    /**
     * Self-skips unless actually run against a real Postgres connection
     * (the default/CI connection is sqlite, see phpunit.xml).
     */
    public function test_postgres_field_specific_track_title_match_works_without_the_old_whole_blob_index(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Only meaningful against a real Postgres connection.');
        }

        // #57's whole-JSON-blob-text trigram index is superseded by #72's
        // field-specific jsonb_array_elements() match, which queries a
        // different expression shape the old index can't accelerate — the
        // migration dropping it should have actually removed it, not left
        // it behind as dead weight on every write.
        $this->assertEmpty(
            DB::select("SELECT indexname FROM pg_indexes WHERE indexname LIKE '%media_cds_tracks_trgm_idx'"),
            'Expected the superseded whole-blob tracks trigram index to have been dropped.',
        );

        $user = $this->actingAsUser();
        $this->createCdLibraryWithTracks($user);

        $exactMatch = $this->getJson('/api/search?query=Paranoid Android&fuzzy=false');
        $exactMatch->assertOk();
        $this->assertCount(1, $exactMatch->json());

        $fuzzyMatch = $this->getJson('/api/search?query=Airbagg&fuzzy=true');
        $fuzzyMatch->assertOk();
        $this->assertCount(1, $fuzzyMatch->json());

        $falsePositive = $this->getJson('/api/search?query=284&fuzzy=false');
        $falsePositive->assertOk();
        $this->assertCount(0, $falsePositive->json());
    }
}
