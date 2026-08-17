<?php

namespace App\Http\Controllers\Api;

use App\Domain\Backup\BackupService;
use App\Http\Controllers\Controller;
use App\Models\Backup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Backup listing, manual creation, download and deletion (briefing 9.2/9.3).
 * Admin-only (see routes/api.php). Automatic scheduled creation is wired in
 * routes/console.php via the configured interval, not here.
 */
class BackupController extends Controller
{
    public function __construct(private readonly BackupService $backupService) {}

    /** reconcileWithDisk() first — see its own docblock — so a backup file that exists on disk without a tracked row (GitHub-reported: 8 shown vs. 50 files actually present) shows up here too, self-healing on every page load rather than staying permanently invisible. */
    public function index()
    {
        $this->backupService->reconcileWithDisk();

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

    /**
     * Manual admin-initiated deletion is logged here, not inside
     * BackupService::delete() — that method is shared with prune()'s
     * automatic retention cleanup, which already logs its own bulk
     * "Backups pruned" entry (with every pruned filename) right before
     * calling delete() per backup; logging again inside delete() itself
     * would double-log every automatically pruned backup. A manually
     * deleted backup previously went completely unlogged — no entry at
     * all, unlike every other backup action (created/restored/pruned).
     */
    public function destroy(Request $request, Backup $backup)
    {
        Log::info('Backup deleted', ['actor_id' => $request->user()->id, 'filename' => $backup->filename]);
        $this->backupService->delete($backup);

        return response()->noContent();
    }

    /** @see BackupService::restore() for current implementation status. */
    public function restore(Request $request, Backup $backup)
    {
        $data = $request->validate([
            'conflict_resolutions' => ['sometimes', 'array'],
            'restore_settings' => ['sometimes', 'boolean'],
            // GitHub issue #80 — a separate opt-in from restore_settings above, see
            // ExportImportService::importLibraries()'s $restoreShares docblock for why.
            'restore_shares' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->backupService->restore(
            $backup,
            $request->user(),
            $data['conflict_resolutions'] ?? [],
            $data['restore_settings'] ?? false,
            $data['restore_shares'] ?? false,
        ));
    }
}
