<?php

namespace App\Http\Controllers\Api;

use App\Domain\Reports\ReportsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** "Auswertungen" (GitHub issue #74) — see ReportsService's docblock for why this is a separate module from StatisticsController rather than an extension of it. */
class ReportsController extends Controller
{
    public function __construct(private readonly ReportsService $reportsService) {}

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
}
