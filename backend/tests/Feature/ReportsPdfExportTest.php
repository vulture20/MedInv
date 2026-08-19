<?php

namespace Tests\Feature;

use App\Domain\ExportPdf\PdfExportService;
use App\Models\Library;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * GitHub issue #87 — PDF export for every "Auswertungen" report
 * (ReportsController::exportPdf(), PdfExportService::reportPdf()). Asserts
 * on the plumbing (routing/auth/headers/a genuine PDF file signature)
 * rather than rendered page content — the actual per-report data shaping
 * was manually verified via pdftotext against a real dev server while
 * building this (see the closing PR/issue comment), and re-asserting
 * dompdf's own rendering correctness here would be brittle for little
 * value, same reasoning MediaItemController's cover-serving tests don't
 * inspect pixel data either.
 */
class ReportsPdfExportTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    /** @return array<int, array{0: string}> */
    public static function reportKeyProvider(): array
    {
        return array_map(fn (string $key) => [$key], PdfExportService::REPORT_KEYS);
    }

    #[DataProvider('reportKeyProvider')]
    public function test_every_report_key_downloads_a_real_pdf(string $key): void
    {
        $this->actingAsUser();

        $response = $this->get("/api/reports/{$key}/export/pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringEndsWith('.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_an_unknown_report_key_404s_instead_of_rendering_anything(): void
    {
        $this->actingAsUser();

        $response = $this->get('/api/reports/not-a-real-report/export/pdf');

        $response->assertNotFound();
    }

    /**
     * Data actually reaches the PDF, not just an empty shell — a duplicate
     * EAN's title should be present as PDF text content. Skipped when
     * poppler-utils' `pdftotext` isn't installed (it isn't part of
     * docker/Dockerfile's runtime image, only happens to be available in
     * some dev/CI environments) rather than failing the whole suite over a
     * tool this app itself never depends on — the plumbing-level test
     * above already covers every environment unconditionally.
     */
    public function test_duplicates_pdf_reflects_real_data(): void
    {
        if (trim(shell_exec('which pdftotext 2>/dev/null') ?? '') === '') {
            $this->markTestSkipped('pdftotext (poppler-utils) not available in this environment.');
        }

        // A "duplicate" is the same EAN across two different libraries of
        // the same media type (briefing 5.1's per-library duplicate-EAN
        // rule only ever compared within one library's own table, see
        // MediaItemService::create()'s docblock) — a real per-library
        // unique constraint means two rows can't share an EAN within the
        // same library at all, so this needs two libraries, not two rows.
        $owner = $this->actingAsUser();
        $libraryA = Library::query()->create(['name' => 'A', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $libraryB = Library::query()->create(['name' => 'B', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $libraryA->id, 'title' => 'PdfExportDuplicateTitle', 'ean' => '9780000000001']);
        MediaBook::query()->create(['library_id' => $libraryB->id, 'title' => 'PdfExportDuplicateTitle', 'ean' => '9780000000001']);

        $response = $this->get('/api/reports/duplicates/export/pdf');

        $response->assertOk();
        // dompdf-generated PDFs use compressed content streams, so the raw
        // title string isn't necessarily findable as plain bytes — decode
        // via a real PDF text extractor instead of a brittle raw-bytes
        // search, same tool used for the manual live verification above.
        $tmp = tempnam(sys_get_temp_dir(), 'medinv-pdf-test');
        file_put_contents($tmp, $response->getContent());
        $text = shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>/dev/null') ?? '';
        unlink($tmp);

        $this->assertStringContainsString('PdfExportDuplicateTitle', $text);
    }
}
