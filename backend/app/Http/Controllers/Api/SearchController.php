<?php

namespace App\Http\Controllers\Api;

use App\Domain\Search\SearchFilters;
use App\Domain\Search\SearchService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Global search across every accessible library (briefing 13.), grown by
 * GitHub issue #73 from a plain free-text-plus-fuzzy-toggle endpoint into a
 * real filter mask — see SearchFilters' own docblock for the full shape.
 */
class SearchController extends Controller
{
    public function __construct(private readonly SearchService $searchService) {}

    public function search(Request $request)
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
        $filters = SearchFilters::fromValidated($data, $request->boolean('fuzzy'));

        return $this->searchService->search($request->user(), $filters);
    }

    /** GitHub issue #73 — populates SearchPage.tsx's attribute filter <select>s with the values that actually occur in the visible collection. */
    public function filterOptions(Request $request)
    {
        return $this->searchService->filterOptionsFor($request->user());
    }
}
