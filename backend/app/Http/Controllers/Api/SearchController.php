<?php

namespace App\Http\Controllers\Api;

use App\Domain\Search\SearchService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Global search across every accessible library (briefing 13.). */
class SearchController extends Controller
{
    public function __construct(private readonly SearchService $searchService) {}

    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'min:1'],
        ]);

        // Not run through the `boolean` validation rule: it only accepts
        // [true, false, 0, 1, '0', '1'] (see ValidatesAttributes::validateBoolean())
        // — a query-string GET param serializes a JS `false` as the literal
        // string "false", which that rule rejects with a 422. Every search
        // failed this way (the frontend always sends `fuzzy`, defaulting to
        // unchecked/false) with no visible error, since SearchPage.tsx's request
        // had no .catch() — see the fix there. Request::boolean() handles the
        // common string representations ("true"/"false"/"1"/"0"/...) instead.
        return $this->searchService->search($request->user(), $data['query'], $request->boolean('fuzzy'));
    }
}
