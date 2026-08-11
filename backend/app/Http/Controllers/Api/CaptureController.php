<?php

namespace App\Http\Controllers\Api;

use App\Domain\Capture\BulkImportService;
use App\Domain\Libraries\LibraryAccessService;
use App\Http\Controllers\Controller;
use App\Models\Library;
use Illuminate\Http\Request;

/**
 * Bulk import endpoints (briefing 7.2). Hardware scanner and camera-based
 * scanning both submit one code at a time to `scan()` — from the backend's
 * point of view they're indistinguishable, since a hardware scanner just
 * types the code + Enter and the camera path decodes it client-side before
 * calling this same endpoint. `textFile()` handles the third path.
 */
class CaptureController extends Controller
{
    public function __construct(
        private readonly LibraryAccessService $access,
        private readonly BulkImportService $bulkImportService,
    ) {}

    public function scan(Request $request, Library $library)
    {
        abort_unless($this->access->canWrite($request->user(), $library), 403);

        $data = $request->validate(['ean' => ['required', 'string']]);

        return response()->json($this->bulkImportService->resolveOne($library, $data['ean']));
    }

    /** Ziel-Bibliothek is the `library` route-model itself, asked for client-side before upload (7.2). */
    public function textFile(Request $request, Library $library)
    {
        abort_unless($this->access->canWrite($request->user(), $library), 403);

        $request->validate(['file' => ['required', 'file']]);

        $eans = $this->bulkImportService->parseEanTextFile($request->file('file')->get());

        return response()->json($this->bulkImportService->resolveMany($library, $eans));
    }
}
