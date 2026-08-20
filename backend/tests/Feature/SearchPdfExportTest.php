<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\LibraryShare;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GitHub issue #121 — PDF export of the search mask's current result set
 * (SearchController::exportPdf(), PdfExportService::searchResultsPdf()), a
 * #73 comment's addendum. Same "assert the plumbing, then a handful of
 * pdftotext content checks for what's genuinely specific to this export"
 * split ReportsPdfExportTest.php/LibraryPdfExportTest.php already use.
 */
class SearchPdfExportTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user', array $overrides = []): User
    {
        // Defaults to 'en' for the same reason LibraryPdfExportTest.php's
        // actingAsUser() does (GitHub issue #113) — every test below not
        // specifically about localization asserts the same English text
        // it always has.
        $user = User::factory()->create(['level' => $level, 'is_active' => true, 'preferred_language' => 'en', ...$overrides]);
        $this->actingAs($user);

        return $user;
    }

    public function test_export_pdf_downloads_a_real_pdf(): void
    {
        $this->actingAsUser();

        $response = $this->get('/api/search/export/pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringEndsWith('.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /** Same validation SearchController::search() itself enforces (SearchFiltersTest.php) — exportPdf() shares filtersFromRequest(), so an invalid `field` must still 422, not silently fall back to 'all'. */
    public function test_an_invalid_field_scope_422s(): void
    {
        $this->actingAsUser();

        $response = $this->get('/api/search/export/pdf?field=not-a-real-field');

        $response->assertStatus(422);
    }

    /** GitHub issue #127 — sort_by is validated against the same seven columns SearchPage.tsx's SortColumn union offers. */
    public function test_an_invalid_sort_by_422s(): void
    {
        $this->actingAsUser();

        $response = $this->get('/api/search/export/pdf?sort_by=not-a-real-column');

        $response->assertStatus(422);
    }

    private function pdfText(TestResponse $response): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'medinv-pdf-test');
        file_put_contents($tmp, $response->getContent());
        $text = shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>/dev/null') ?? '';
        unlink($tmp);

        return $text;
    }

    private function skipUnlessPdftotextAvailable(): void
    {
        if (trim(shell_exec('which pdftotext 2>/dev/null') ?? '') === '') {
            $this->markTestSkipped('pdftotext (poppler-utils) not available in this environment.');
        }
    }

    public function test_filter_summary_lists_the_active_criteria(): void
    {
        $this->skipUnlessPdftotextAvailable();
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'genre' => 'Sci-Fi']);

        $response = $this->get('/api/search/export/pdf?query=Dune&genre[]=Sci-Fi&media_types[]=book&price_min=5');

        $response->assertOk();
        $text = $this->pdfText($response);
        $this->assertStringContainsString('Search term: Dune', $text);
        $this->assertStringContainsString('Media type: Book', $text);
        $this->assertStringContainsString('Genre (Book): Sci-Fi', $text);
        $this->assertStringContainsString('Price: ≥ 5', $text);
    }

    public function test_no_active_filters_shows_the_fallback_text_and_every_visible_item(): void
    {
        $this->skipUnlessPdftotextAvailable();
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);

        $response = $this->get('/api/search/export/pdf');

        $response->assertOk();
        $text = $this->pdfText($response);
        $this->assertStringContainsString('No filter criteria', $text);
        $this->assertStringContainsString('Dune', $text);
    }

    public function test_results_table_shows_matching_items_with_their_library(): void
    {
        $this->skipUnlessPdftotextAvailable();
        $owner = $this->actingAsUser();
        $bookLibrary = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $bookLibrary->id, 'title' => 'Dune', 'ean' => '9780000000001', 'location' => 'Shelf 3']);
        $cdLibrary = Library::query()->create(['name' => 'Albums', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        MediaCd::query()->create(['library_id' => $cdLibrary->id, 'title' => 'OK Computer', 'ean' => '9780000000002']);

        $response = $this->get('/api/search/export/pdf?media_types[]=book');

        $response->assertOk();
        $text = $this->pdfText($response);
        $this->assertStringContainsString('Dune', $text);
        $this->assertStringContainsString('Novels', $text);
        $this->assertStringContainsString('Shelf 3', $text);
        // media_types[]=book excludes the CD from the result set entirely.
        $this->assertStringNotContainsString('OK Computer', $text);
    }

    /** GitHub issue #127 — the exported row order must match sort_by/sort_dir, the same params SearchPage.tsx now sends whether the sort was set via its "Sortieren nach" <select> or by clicking a column header. */
    public function test_sort_by_and_sort_dir_control_the_exported_row_order(): void
    {
        $this->skipUnlessPdftotextAvailable();
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Zebra', 'ean' => '9780000000001']);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Apple', 'ean' => '9780000000002']);

        $ascResponse = $this->get('/api/search/export/pdf?sort_by=title&sort_dir=asc');
        $ascResponse->assertOk();
        $ascending = $this->pdfText($ascResponse);
        $this->assertLessThan(strpos($ascending, 'Zebra'), strpos($ascending, 'Apple'));

        $descResponse = $this->get('/api/search/export/pdf?sort_by=title&sort_dir=desc');
        $descResponse->assertOk();
        $descending = $this->pdfText($descResponse);
        $this->assertLessThan(strpos($descending, 'Apple'), strpos($descending, 'Zebra'));
    }

    /** Same "not shared -> not findable" rule search/statistics already enforce end-to-end (LibraryVisibilityInSearchAndStatisticsTest.php) — the PDF export must not become a side door around it. */
    public function test_an_unshared_librarys_items_do_not_appear_in_the_exported_pdf(): void
    {
        $this->skipUnlessPdftotextAvailable();
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Secret Stash', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'FindMeNot9284', 'ean' => '9780000000003']);
        $this->actingAsUser();

        $response = $this->get('/api/search/export/pdf');

        $response->assertOk();
        $this->assertStringNotContainsString('FindMeNot9284', $this->pdfText($response));
    }

    public function test_a_guest_can_export_a_library_explicitly_shared_with_guests(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Shared With Guests', 'media_type' => 'book', 'owner_id' => $owner->id]);
        LibraryShare::query()->create(['library_id' => $library->id, 'scope' => 'guest']);
        $this->actingAsUser('guest');

        $response = $this->get('/api/search/export/pdf');

        $response->assertOk();
    }
}
