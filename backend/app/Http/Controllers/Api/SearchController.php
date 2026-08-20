<?php

namespace App\Http\Controllers\Api;

use App\Domain\ExportPdf\PdfExportService;
use App\Domain\Search\SearchFilters;
use App\Domain\Search\SearchService;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Global search across every accessible library (briefing 13.), grown by
 * GitHub issue #73 from a plain free-text-plus-fuzzy-toggle endpoint into a
 * real filter mask — see SearchFilters' own docblock for the full shape.
 */
class SearchController extends Controller
{
    public function __construct(
        private readonly SearchService $searchService,
        private readonly PdfExportService $pdfExportService,
    ) {}

    public function search(Request $request)
    {
        $filters = $this->filtersFromRequest($request);

        return $this->searchService->search($request->user(), $filters);
    }

    /** GitHub issue #73 — populates SearchPage.tsx's attribute filter <select>s with the values that actually occur in the visible collection. */
    public function filterOptions(Request $request)
    {
        return $this->searchService->filterOptionsFor($request->user());
    }

    /** GitHub issue #121 — the current search result set as a PDF, same filter params as search() above (a plain GET, matching how SearchPage.tsx's own request-building already works) so the export always reflects exactly the criteria that were actually applied, not a second, separately-tracked "what was last searched for". */
    public function exportPdf(Request $request)
    {
        $filters = $this->filtersFromRequest($request);

        $pdf = $this->pdfExportService->searchResultsPdf($request->user(), $filters);
        $filename = 'medinv-search-'.SystemSetting::localNow()->format('Ymd-His').'.pdf';

        return $pdf->download($filename);
    }

    /** Shared by search()/exportPdf() — the exact same filter params drive both, so a PDF export always matches what's on screen. */
    private function filtersFromRequest(Request $request): SearchFilters
    {
        $data = $request->validate([
            // No longer `required` (GitHub issue #73) — a request can now
            // consist entirely of attribute/range filters with no free
            // text at all, browsing rather than searching.
            'query' => ['nullable', 'string'],
            'field' => ['nullable', Rule::in(['all', 'title', 'creator', 'description', 'identifier', 'location', 'tracks'])],
            'media_types' => ['array'],
            'media_types.*' => [Rule::in(['book', 'cd', 'dvd_bluray'])],
            'library_ids' => ['array'],
            'library_ids.*' => ['integer'],
            'genre' => ['array'],
            'genre.*' => ['string'],
            'format' => ['array'],
            'format.*' => ['string'],
            'language' => ['array'],
            'language.*' => ['string'],
            'medium' => ['array'],
            'medium.*' => ['string'],
            'languages' => ['array'],
            'languages.*' => ['string'],
            'price_min' => ['nullable', 'numeric'],
            'price_max' => ['nullable', 'numeric'],
            'year_min' => ['nullable', 'integer'],
            'year_max' => ['nullable', 'integer'],
            'page_count_min' => ['nullable', 'integer'],
            'page_count_max' => ['nullable', 'integer'],
            'disc_count_min' => ['nullable', 'integer'],
            'disc_count_max' => ['nullable', 'integer'],
            'runtime_min' => ['nullable', 'integer'],
            'runtime_max' => ['nullable', 'integer'],
        ]);

        // Not run through the `boolean` validation rule: it only accepts
        // [true, false, 0, 1, '0', '1'] (see ValidatesAttributes::validateBoolean())
        // — a query-string GET param serializes a JS `false` as the literal
        // string "false", which that rule rejects with a 422. Every search
        // failed this way (the frontend always sends `fuzzy`, defaulting to
        // unchecked/false) with no visible error, since SearchPage.tsx's request
        // had no .catch() — see the fix there. Request::boolean() handles the
        // common string representations ("true"/"false"/"1"/"0"/...) instead.
        return SearchFilters::fromValidated($data, $request->boolean('fuzzy'));
    }
}
