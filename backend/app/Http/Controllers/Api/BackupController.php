<?php

namespace App\Http\Controllers\Api;

use App\Domain\Backup\BackupService;
use App\Http\Controllers\Controller;
use App\Models\Backup;
use Illuminate\Http\Request;

/**
 * Backup listing, manual creation, download and deletion (briefing 9.2/9.3).
 * Admin-only (see routes/api.php). Automatic scheduled creation is wired in
 * routes/console.php via the configured interval, not here.
 */
class BackupController extends Controller
{
    public function __construct(private readonly BackupService $backupService) {}

    public function index()
    {
        return Backup::query()->latest()->get();
    }

    public function store()
    {
        return response()->json($this->backupService->create(trigger: 'manual'), 201);
    }

    public function download(Backup $backup)
    {
        return response()->download($this->backupService->download($backup), $backup->filename);
    }

    public function destroy(Backup $backup)
    {
        $this->backupService->delete($backup);

        return response()->noContent();
    }

    /** @see BackupService::restore() for current implementation status. */
    public function restore(Request $request, Backup $backup)
    {
        $data = $request->validate(['conflict_resolutions' => ['sometimes', 'array']]);

        return response()->json(
            $this->backupService->restore($backup, $request->user(), $data['conflict_resolutions'] ?? [])
        );
    }
}
