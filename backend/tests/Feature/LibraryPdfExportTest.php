<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\LibraryShare;
use App\Models\MediaBook;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GitHub issue #87 — a single library's own printable/archivable PDF
 * inventory list (LibraryController::exportPdf(),
 * PdfExportService::libraryInventoryPdf()). Same "assert the plumbing, not
 * dompdf's own rendering" reasoning as ReportsPdfExportTest.
 */
class LibraryPdfExportTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_the_owner_can_download_a_pdf_inventory_of_their_library(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'price' => 12]);

        $response = $this->get("/api/libraries/{$library->id}/export/pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringEndsWith('.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /** Free-text library names can contain filesystem-unsafe characters (briefing 5.) — the filename must still come out safe, same reasoning ExportImportController's own filename sanitizing already documents. */
    public function test_the_download_filename_sanitizes_the_library_name(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Sci-Fi / Fantasy: "Best of"', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->get("/api/libraries/{$library->id}/export/pdf");

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringNotContainsString('/', explode('filename=', $disposition)[1]);
        $this->assertStringNotContainsString('"Best', $disposition);
    }

    /** LibraryAccessService::canRead() — same gate show()/the item routes already use, not the stricter canWrite(). */
    public function test_a_user_without_read_access_cannot_export_the_pdf(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Private', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $this->actingAsUser();

        $response = $this->get("/api/libraries/{$library->id}/export/pdf");

        $response->assertForbidden();
    }

    public function test_a_guest_can_export_a_library_explicitly_shared_with_guests(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Shared With Guests', 'media_type' => 'book', 'owner_id' => $owner->id]);
        LibraryShare::query()->create(['library_id' => $library->id, 'scope' => 'guest']);
        $this->actingAsUser('guest');

        $response = $this->get("/api/libraries/{$library->id}/export/pdf");

        $response->assertOk();
    }

    public function test_exporting_a_nonexistent_library_404s(): void
    {
        $this->actingAsUser();

        $response = $this->get('/api/libraries/999999/export/pdf');

        $response->assertNotFound();
    }

    /** Extracts a PDF response's text content via poppler-utils' pdftotext, same tool ReportsPdfExportTest's own content test uses — see that test's docblock for why (dompdf's compressed streams make a raw-bytes search unreliable). */
    private function pdfText(TestResponse $response): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'medinv-pdf-test');
        file_put_contents($tmp, $response->getContent());
        $text = shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>/dev/null') ?? '';
        unlink($tmp);

        return $text;
    }

    /**
     * GitHub issue #107 — a library's total_value shows an actual currency
     * symbol when every item agrees with the admin-configured default
     * currency, same rule StatisticsService::overviewFor() already applies
     * to its own per-library total (see PdfExportService::
     * libraryInventoryPdf()'s docblock).
     */
    public function test_the_total_value_shows_a_currency_symbol_when_every_item_matches_the_default_currency(): void
    {
        if (trim(shell_exec('which pdftotext 2>/dev/null') ?? '') === '') {
            $this->markTestSkipped('pdftotext (poppler-utils) not available in this environment.');
        }

        $owner = $this->actingAsUser();
        SystemSetting::set('statistics.default_currency', 'EUR');
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'price' => 12, 'currency' => 'EUR']);

        $response = $this->get("/api/libraries/{$library->id}/export/pdf");

        $response->assertOk();
        $this->assertStringContainsString('€12', $this->pdfText($response));
    }

    /** A mismatched item's currency makes the sum untrustworthy to label with any single currency — same reasoning the on-screen warning banner (statistics.currencyMismatchWarning) already documents. */
    public function test_the_total_value_stays_a_bare_number_on_a_currency_mismatch(): void
    {
        if (trim(shell_exec('which pdftotext 2>/dev/null') ?? '') === '') {
            $this->markTestSkipped('pdftotext (poppler-utils) not available in this environment.');
        }

        $owner = $this->actingAsUser();
        SystemSetting::set('statistics.default_currency', 'EUR');
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'price' => 12, 'currency' => 'USD']);

        $response = $this->get("/api/libraries/{$library->id}/export/pdf");

        $response->assertOk();
        $text = $this->pdfText($response);
        $this->assertStringContainsString('total value 12', $text);
        $this->assertStringNotContainsString('€', $text);
    }
}
