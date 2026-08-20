<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\MediaDvdBluray;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #74 — App\Domain\Reports\ReportsService via its HTTP
 * endpoints, one test per report. Each report's own defining behavior is
 * covered here (not an exhaustive re-test of every helper) plus a single
 * shared visibility check — every report is built on
 * LibraryAccessService::visibleLibrariesQuery() like Search/Statistics, so
 * one representative test (duplicates) stands in for the rest, the same
 * economy LibraryVisibilityInSearchAndStatisticsTest already takes for
 * Search vs. Statistics.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_duplicates_groups_the_same_ean_across_libraries_of_the_same_media_type(): void
    {
        $owner = $this->actingAsUser();
        $libraryA = Library::query()->create(['name' => 'A', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $libraryB = Library::query()->create(['name' => 'B', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $itemA = MediaBook::query()->create(['library_id' => $libraryA->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $itemB = MediaBook::query()->create(['library_id' => $libraryB->id, 'title' => 'Dune (2nd copy)', 'ean' => '9780000000001']);
        MediaBook::query()->create(['library_id' => $libraryA->id, 'title' => 'Unique', 'ean' => '9780000000002']);

        $response = $this->getJson('/api/reports/duplicates');

        $response->assertOk();
        $groups = collect($response->json());
        $this->assertCount(1, $groups);
        $group = $groups->first();
        $this->assertSame('9780000000001', $group['ean']);
        $this->assertSame(['book'], collect($group['items'])->pluck('media_type')->unique()->all());
        $this->assertEqualsCanonicalizing([$itemA->id, $itemB->id], collect($group['items'])->pluck('id')->all());
    }

    /** #74's own correction comment: an identical EAN on a book and a DVD/Blu-ray is coincidence, not a duplicate — the dupe check must never span media types. */
    public function test_duplicates_never_matches_the_same_ean_across_different_media_types(): void
    {
        $owner = $this->actingAsUser();
        $bookLibrary = Library::query()->create(['name' => 'Books', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $dvdLibrary = Library::query()->create(['name' => 'DVDs', 'media_type' => 'dvd_bluray', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $bookLibrary->id, 'title' => 'Coincidence', 'ean' => '4006381333931']);
        MediaDvdBluray::query()->create(['library_id' => $dvdLibrary->id, 'title' => 'Coincidence', 'ean' => '4006381333931']);

        $response = $this->getJson('/api/reports/duplicates');

        $response->assertOk();
        $this->assertSame([], $response->json());
    }

    public function test_data_quality_lists_items_missing_core_fields(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Books', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $incomplete = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Bare', 'ean' => '9780000000001']);
        MediaBook::query()->create([
            'library_id' => $library->id, 'title' => 'Full', 'ean' => '9780000000002',
            'cover_path' => 'covers/x.jpg', 'description' => 'A book.', 'price' => 9.99, 'page_count' => 200,
        ]);

        $response = $this->getJson('/api/reports/data-quality');

        $response->assertOk();
        $rows = collect($response->json());
        $this->assertTrue($rows->contains('id', $incomplete->id));
        $this->assertFalse($rows->contains('title', 'Full'));
        $missing = $rows->firstWhere('id', $incomplete->id)['missing_fields'];
        $this->assertEqualsCanonicalizing(['cover_path', 'description', 'price', 'page_count'], $missing);
    }

    public function test_top_lists_ranks_by_price_across_media_types_and_runtime_within_a_type(): void
    {
        $owner = $this->actingAsUser();
        $bookLibrary = Library::query()->create(['name' => 'Books', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $cdLibrary = Library::query()->create(['name' => 'CDs', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        $cheapBook = MediaBook::query()->create(['library_id' => $bookLibrary->id, 'title' => 'Cheap', 'ean' => '9780000000001', 'price' => 5]);
        $expensiveCd = MediaCd::query()->create(['library_id' => $cdLibrary->id, 'title' => 'Pricey', 'ean' => '9780000000002', 'price' => 500]);
        $longCd = MediaCd::query()->create(['library_id' => $cdLibrary->id, 'title' => 'Long', 'ean' => '9780000000003', 'runtime_seconds' => 5000]);
        $shortCd = MediaCd::query()->create(['library_id' => $cdLibrary->id, 'title' => 'Short', 'ean' => '9780000000004', 'runtime_seconds' => 100]);

        $response = $this->getJson('/api/reports/top-lists');

        $response->assertOk();
        $data = $response->json();
        $this->assertSame($expensiveCd->id, $data['most_expensive'][0]['id']);
        $this->assertSame($cheapBook->id, $data['cheapest'][0]['id']);
        $this->assertSame($longCd->id, $data['longest_cd_runtime'][0]['id']);
        $this->assertSame($shortCd->id, $data['shortest_cd_runtime'][0]['id']);
    }

    public function test_recent_additions_orders_across_media_types_newest_first(): void
    {
        $owner = $this->actingAsUser();
        $bookLibrary = Library::query()->create(['name' => 'Books', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $cdLibrary = Library::query()->create(['name' => 'CDs', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        $older = MediaBook::query()->create(['library_id' => $bookLibrary->id, 'title' => 'Older', 'ean' => '9780000000001', 'created_at' => now()->subDays(2)]);
        $newer = MediaCd::query()->create(['library_id' => $cdLibrary->id, 'title' => 'Newer', 'ean' => '9780000000002', 'created_at' => now()]);

        $response = $this->getJson('/api/reports/recent-additions');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertSame([$newer->id, $older->id], $ids);
    }

    public function test_capture_source_reports_method_and_provider_per_item(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Books', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create([
            'library_id' => $library->id, 'title' => 'Manual', 'ean' => '9780000000001',
            'capture_method' => 'manual', 'captured_by_user_id' => $owner->id,
        ]);
        // GitHub issue #149: realistic stored values are the full,
        // media-type-scoped provider_key (e.g. "book.open_library"), not
        // the bare provider name — see provider key()'s own return value.
        MediaBook::query()->create([
            'library_id' => $library->id, 'title' => 'Scanned', 'ean' => '9780000000002',
            'capture_method' => 'scan', 'metadata_provider' => 'book.open_library,book.google_books', 'captured_by_user_id' => $owner->id,
        ]);

        $response = $this->getJson('/api/reports/capture-source');

        $response->assertOk();
        $data = $response->json();
        $this->assertSame(1, $data['by_capture_method']['manual']);
        $this->assertSame(1, $data['by_capture_method']['scan']);
        $this->assertSame(1, $data['by_metadata_provider']['open_library']);
        $this->assertSame(1, $data['by_metadata_provider']['google_books']);
    }

    /**
     * GitHub issue #149: a provider that exists for more than one media
     * type (e.g. Amazon: book.amazon/cd.amazon/dvd_bluray.amazon) used to
     * count as several distinct by_metadata_provider entries, one per
     * media type, instead of a single combined total.
     */
    public function test_capture_source_merges_the_same_provider_across_media_types(): void
    {
        $owner = $this->actingAsUser();
        $bookLibrary = Library::query()->create(['name' => 'Books', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $cdLibrary = Library::query()->create(['name' => 'CDs', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        $dvdLibrary = Library::query()->create(['name' => 'Films', 'media_type' => 'dvd_bluray', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $bookLibrary->id, 'title' => 'A Book', 'ean' => '9780000000001', 'metadata_provider' => 'book.amazon']);
        MediaCd::query()->create(['library_id' => $cdLibrary->id, 'title' => 'A CD', 'ean' => '9780000000002', 'metadata_provider' => 'cd.amazon']);
        MediaDvdBluray::query()->create(['library_id' => $dvdLibrary->id, 'title' => 'A Film', 'ean' => '9780000000003', 'metadata_provider' => 'dvd_bluray.amazon']);

        $response = $this->getJson('/api/reports/capture-source');

        $response->assertOk();
        $data = $response->json();
        $this->assertSame(3, $data['by_metadata_provider']['amazon']);
        $this->assertArrayNotHasKey('book.amazon', $data['by_metadata_provider']);
    }

    public function test_an_unshared_librarys_items_are_invisible_to_every_report(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $libraryA = Library::query()->create(['name' => 'Secret Stash A', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $libraryB = Library::query()->create(['name' => 'Secret Stash B', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $itemA = MediaBook::query()->create(['library_id' => $libraryA->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        MediaBook::query()->create(['library_id' => $libraryB->id, 'title' => 'Dune (2nd copy)', 'ean' => '9780000000001']);
        $this->actingAsUser();

        $response = $this->getJson('/api/reports/duplicates');

        $response->assertOk();
        $this->assertSame([], $response->json());

        $recent = $this->getJson('/api/reports/recent-additions');
        $recent->assertOk();
        $this->assertFalse(collect($recent->json())->contains('id', $itemA->id));
    }
}
