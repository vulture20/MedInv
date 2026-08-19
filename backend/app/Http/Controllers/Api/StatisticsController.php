<?php

namespace App\Http\Controllers\Api;

use App\Domain\Statistics\StatisticsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Bestand statistics for the requesting user's accessible libraries (briefing 14.). */
class StatisticsController extends Controller
{
    public function __construct(private readonly StatisticsService $statisticsService) {}

    public function __invoke(Request $request)
    {
        return $this->statisticsService->overviewFor($request->user());
    }

    /** Value-over-time (briefing 14., GitHub issue #30) — see StatisticsService::valueHistoryFor(). */
    public function valueHistory(Request $request)
    {
        return $this->statisticsService->valueHistoryFor($request->user());
    }
}
