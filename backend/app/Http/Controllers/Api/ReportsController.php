<?php

namespace App\Http\Controllers\Api;

use App\Domain\ExportPdf\PdfExportService;
use App\Domain\Reports\ReportsService;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

/** "Auswertungen" (GitHub issue #74) — see ReportsService's docblock for why this is a separate module from StatisticsController rather than an extension of it. */
class ReportsController extends Controller
{
    public function __construct(
        private readonly ReportsService $reportsService,
        private readonly PdfExportService $pdfExportService,
    ) {}

    public function duplicates(Request $request)
    {
        return $this->reportsService->duplicatesFor($request->user());
    }

    public function dataQuality(Request $request)
    {
        return $this->reportsService->dataQualityFor($request->user());
    }

    public function topLists(Request $request)
    {
        return $this->reportsService->topListsFor($request->user());
    }

    public function recentAdditions(Request $request)
    {
        return $this->reportsService->recentAdditionsFor($request->user(), $request->integer('limit', 50));
    }

    public function captureSource(Request $request)
    {
        return $this->reportsService->captureSourceFor($request->user());
    }

    /** Sharing overview (GitHub issue #74, moved here from StatisticsController by GitHub issue #103) — see ReportsService::sharingFor(). */
    public function sharing(Request $request)
    {
        return $this->reportsService->sharingFor($request->user());
    }

    /** Per-user capture activity (GitHub issue #74, moved here from StatisticsController by GitHub issue #103) — see ReportsService::userActivityFor(). */
    public function userActivity(Request $request)
    {
        return $this->reportsService->userActivityFor($request->user());
    }

    /**
     * PDF export (GitHub issue #87) for any of the report keys above —
     * `{key}` is a route parameter, not a model binding, since there's no
     * Eloquent model behind a report; validated against
     * PdfExportService::REPORT_KEYS (mirroring frontend/src/pages/reports/
     * reportTypes.ts's own REPORTS/ReportKey) rather than trusting whatever
     * string arrives. No separate access check beyond that: every
     * ReportsService method this ends up calling already scopes through
     * LibraryAccessService itself, exactly like the JSON endpoints above.
     */
    public function exportPdf(Request $request, string $key)
    {
        abort_unless(in_array($key, PdfExportService::REPORT_KEYS, true), 404);

        $pdf = $this->pdfExportService->reportPdf($request->user(), $key);
        $filename = 'medinv-'.$key.'-'.SystemSetting::localNow()->format('Ymd-His').'.pdf';

        return $pdf->download($filename);
    }
}
