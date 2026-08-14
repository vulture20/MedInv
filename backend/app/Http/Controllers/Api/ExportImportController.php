<?php

namespace App\Http\Controllers\Api;

use App\Domain\ExportImport\ExportImportService;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

        Log::info('Libraries exported', [
            'actor_id' => $request->user()->id,
            'library_ids' => $libraryIds ?? 'all',
            'library_count' => count($export['libraries'] ?? []),
        ]);

        // SystemSetting::localNow(), not now() — GitHub issue #31: this filename
        // is what the admin actually sees in their downloads, so it should
        // reflect their configured display timezone, not always UTC.
        return Response::json($export)
            ->header('Content-Disposition', 'attachment; filename="medinv-export-'.SystemSetting::localNow()->format('Ymd-His').'.json"');
    }

    /**
     * Same conflict-resolution options as backup restore (briefing 9.1 + 9.3):
     * rename | merge | overwrite | skip per library name, or __all__=cancel.
     * `restore_settings` opts into also applying the file's system_settings
     * (see ExportImportService::exportLibraries()) onto this instance —
     * off by default so an ordinary library import can't silently change
     * mail/backup/security configuration.
     */
    public function import(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file'],
            'conflict_resolutions' => ['sometimes', 'array'],
            'restore_settings' => ['sometimes', 'boolean'],
        ]);

        $payload = json_decode($data['file']->get(), true);
        abort_if(json_last_error() !== JSON_ERROR_NONE, 422, 'Invalid export file.');

        $result = $this->service->importLibraries(
            $payload,
            $request->user(),
            $data['conflict_resolutions'] ?? [],
            $data['restore_settings'] ?? false,
        );

        // Import can overwrite/merge existing libraries — same audit-trail
        // motivation as BackupService::restore()'s logging, which this
        // deliberately mirrors (same importLibraries() result shape).
        Log::info('Libraries imported', [
            'actor_id' => $request->user()->id,
            'restore_settings' => $data['restore_settings'] ?? false,
            'created' => count($result['created'] ?? []),
            'merged' => count($result['merged'] ?? []),
            'overwritten' => count($result['overwritten'] ?? []),
            'skipped' => count($result['skipped'] ?? []),
        ]);

        return response()->json($result);
    }
}
