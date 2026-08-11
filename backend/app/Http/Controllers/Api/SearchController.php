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
            'fuzzy' => ['sometimes', 'boolean'],
        ]);

        return $this->searchService->search($request->user(), $data['query'], (bool) ($data['fuzzy'] ?? false));
    }
}
