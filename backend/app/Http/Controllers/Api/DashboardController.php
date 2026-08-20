<?php

namespace App\Http\Controllers\Api;

use App\Domain\Search\SearchService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Startseite (briefing 11.2, GitHub issue #116) — a fresh, random selection
 * of media per media type, across every library visible to the requesting
 * user, feeding DashboardPage.tsx's three cover carousels.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly SearchService $searchService) {}

    public function randomItems(Request $request)
    {
        return $this->searchService->randomItemsFor($request->user());
    }
}
