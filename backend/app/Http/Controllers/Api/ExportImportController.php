<?php

namespace App\Http\Controllers\Api;

use App\Domain\ExportImport\ExportImportService;
use App\Domain\ExportImport\InvalidImportFileException;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use ZipArchive;

/**
 * Instance-to-instance export/import (briefing 9.1), selectable by single,
 * multiple or all libraries. Admin-only (see routes/api.php) since export
 * bypasses per-library share checks by design (an admin exporting "alle"
 * needs everything, not just what's shared with them).
 */
class ExportImportController extends Controller
{
    public function __construct(private readonly ExportImportService $service) {}

    /**
     * `library_ids` omitted or empty means "alle" (briefing 9.1). Exported as
     * a zip (manifest.json + every referenced cover image under `covers/`),
     * the same shape BackupService produces — GitHub issue #26 fixed this
     * for backups, but an ordinary library export still shipped bare
     * manifest JSON with every cover silently lost on the receiving end,
     * since cover_path only resolves relative to *this* instance's `local`
     * disk (see CoverDownloadService). import() below only accepts this zip
     * format now — a bare JSON file is rejected, see readImportPayload().
     */
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

        $tmpJson = tempnam(sys_get_temp_dir(), 'medinv-export');
        file_put_contents($tmpJson, json_encode($export, JSON_PRETTY_PRINT));

        $tmpZip = tempnam(sys_get_temp_dir(), 'medinv-export-zip');
        $zip = new ZipArchive;
        $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($tmpJson, 'manifest.json');
        $this->service->addCoverFilesToZip($zip, $export);
        $zip->close();
        unlink($tmpJson);

        // SystemSetting::localNow(), not now() — GitHub issue #31: this filename
        // is what the admin actually sees in their downloads, so it should
        // reflect their configured display timezone, not always UTC.
        $filename = 'medinv-export-'.SystemSetting::localNow()->format('Ymd-His').'.zip';

        return Response::download($tmpZip, $filename, ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }

    /**
     * Same conflict-resolution options as backup restore (briefing 9.1 + 9.3):
     * rename | merge | overwrite | skip per library name, or __all__=cancel.
     * `restore_settings` opts into also applying the file's system_settings
     * (see ExportImportService::exportLibraries()) onto this instance —
     * off by default so an ordinary library import can't silently change
     * mail/backup/security configuration. Still accepted here for API
     * completeness/future use, but ExportImportPage.tsx deliberately never
     * sends it: export() never sets $includeUsers, so a plain export's
     * `restore_settings` could only ever have restored system_settings
     * (never the user accounts the option's label — copied from
     * BackupsPage's restore form, where a real backup does include them —
     * would otherwise promise on this page).
     */
    public function import(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file'],
            'conflict_resolutions' => ['sometimes', 'array'],
            'restore_settings' => ['sometimes', 'boolean'],
        ]);

        $payload = $this->readImportPayload($data['file']);
        if ($payload === null) {
            return $this->invalidImportResponse($request, 'import_invalid_json');
        }

        try {
            $result = $this->service->importLibraries(
                $payload,
                $request->user(),
                $data['conflict_resolutions'] ?? [],
                $data['restore_settings'] ?? false,
            );
        } catch (InvalidImportFileException $e) {
            return $this->invalidImportResponse($request, $e->errorCode, $e->context);
        }

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

    /**
     * Only the zip format export() produces (manifest.json + every
     * referenced cover under `covers/`) is accepted — a bare JSON file, the
     * only format this endpoint used to accept, is rejected outright, since
     * it can never carry covers at all (cover_path only resolves relative
     * to the *exporting* instance's own `local` disk, see
     * CoverDownloadService) and silently produced imports with every cover
     * missing. A cover found in the zip is written to disk immediately,
     * before importLibraries() (re-)creates the items that reference it —
     * same ordering as BackupService::restore(), see
     * ExportImportService::restoreCoverFilesFromZip()'s docblock. Returns
     * null for anything that isn't a well-formed zip with a valid
     * manifest.json inside.
     */
    private function readImportPayload(UploadedFile $file): ?array
    {
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            return null;
        }

        $manifest = $zip->getFromName('manifest.json');
        if ($manifest === false) {
            $zip->close();

            return null;
        }

        $payload = json_decode($manifest, true);
        if (! is_array($payload)) {
            $zip->close();

            return null;
        }

        $this->service->restoreCoverFilesFromZip($zip);
        $zip->close();

        return $payload;
    }

    /**
     * See InvalidImportFileException's docblock for what this replaces (a
     * silent all-zero "success" or a raw 500 with no usable message).
     * $context is handed to the frontend as-is (an index and/or field name)
     * so ExportImportPage.tsx can build a specific message via its
     * `admin.errors.<code>` i18n key instead of falling back to the generic
     * translation every other unrecognized error shape gets.
     */
    private function invalidImportResponse(Request $request, string $code, array $context = [])
    {
        $this->logApiError($request, $code, "Rejected import file ({$code}).", context: $context);

        return response()->json(['error_code' => $code, 'context' => $context], 422);
    }
}
