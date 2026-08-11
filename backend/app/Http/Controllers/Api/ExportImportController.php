<?php

namespace App\Http\Controllers\Api;

use App\Domain\ExportImport\ExportImportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

/**
 * Instance-to-instance export/import (briefing 9.1), selectable by single,
 * multiple or all libraries. Admin-only (see routes/api.php) since export
 * bypasses per-library share checks by design (an admin exporting "alle"
 * needs everything, not just what's shared with them).
 */
class ExportImportController extends Controller
{
    public function __construct(private readonly ExportImportService $service) {}

    /** `library_ids` omitted or empty means "alle" (briefing 9.1). */
    public function export(Request $request)
    {
        $data = $request->validate(['library_ids' => ['sometimes', 'array'], 'library_ids.*' => ['integer']]);
        $libraryIds = empty($data['library_ids']) ? null : $data['library_ids'];

        $export = $this->service->exportLibraries($libraryIds);

        return Response::json($export)
            ->header('Content-Disposition', 'attachment; filename="medinv-export-'.now()->format('Ymd-His').'.json"');
    }

    /**
     * Same conflict-resolution options as backup restore (briefing 9.1 + 9.3):
     * rename | merge | overwrite | skip per library name, or __all__=cancel.
     */
    public function import(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file'],
            'conflict_resolutions' => ['sometimes', 'array'],
        ]);

        $payload = json_decode($data['file']->get(), true);
        abort_if(json_last_error() !== JSON_ERROR_NONE, 422, 'Invalid export file.');

        $result = $this->service->importLibraries($payload, $request->user(), $data['conflict_resolutions'] ?? []);

        return response()->json($result);
    }
}
