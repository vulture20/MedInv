<?php

namespace App\Http\Controllers\Api;

use App\Domain\Libraries\DuplicateEanException;
use App\Domain\Libraries\LibraryAccessService;
use App\Domain\Libraries\MediaItemService;
use App\Domain\Metadata\CoverDownloadService;
use App\Domain\Metadata\MetadataImportService;
use App\Http\Controllers\Controller;
use App\Models\Library;
use App\Models\MetadataPlugin;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Metadata search/import (briefing 8.). Chosen-candidate confirmation
 * (`import()`) is a thin wrapper around MediaItemService — the candidate's
 * `attributes` are merged straight into the create call, then the resulting
 * record can still be edited before saving on the frontend (8.3 step 6).
 */
class MetadataController extends Controller
{
    public function __construct(
        private readonly LibraryAccessService $access,
        private readonly MetadataImportService $importService,
        private readonly MediaItemService $mediaItemService,
        private readonly CoverDownloadService $coverDownloadService,
    ) {}

    /** All admin-visible plugins, or only those enabled for a media type (briefing 15.). */
    public function plugins(Request $request)
    {
        $query = MetadataPlugin::query();

        if ($mediaType = $request->query('media_type')) {
            $query->where('media_type', $mediaType);
        }

        return $query->orderBy('priority')->get();
    }

    public function search(Request $request, Library $library)
    {
        abort_unless($this->access->canWrite($request->user(), $library), 403);

        $data = $request->validate(['query' => ['required', 'string']]);

        return response()->json($this->importService->search($library, $data['query']));
    }

    /**
     * Confirms one previously returned candidate and creates the media
     * record from it (briefing 8.3, steps 4-6). The user may also reject
     * all candidates client-side and call MediaItemController::store()
     * directly instead — this endpoint is purely opt-in.
     */
    public function import(Request $request, Library $library)
    {
        abort_unless($this->access->canWrite($request->user(), $library), 403);

        $data = $request->validate([
            'attributes' => ['required', 'array'],
            'cover_url' => ['nullable', 'string'],
        ]);

        // Deliberately not an `attributes.ean` validation rule: combining a top-level
        // 'array' rule with a rule on one specific nested key makes Laravel treat
        // `attributes` as "structured" and silently drop every OTHER key from
        // validate()'s output (title, authors, ... — everything except `ean` itself)
        // instead of passing them through to MediaItemService::create() below, which
        // needs the full, media-type-varying attribute set, not just `ean`. Confirmed
        // via a failing NOT NULL constraint in testing before this was caught.
        if (empty($data['attributes']['ean']) || ! is_string($data['attributes']['ean'])) {
            throw ValidationException::withMessages(['attributes.ean' => 'The attributes.ean field is required.']);
        }

        try {
            $item = $this->mediaItemService->create($library, $data['attributes']);
        } catch (DuplicateEanException $e) {
            return response()->json(['message' => $e->getMessage(), 'ean' => $e->ean], 409);
        }

        if (! empty($data['cover_url'])) {
            $coverPath = $this->coverDownloadService->download($data['cover_url'], $library->media_type, $item->ean);

            if ($coverPath) {
                $item->update(['cover_path' => $coverPath]);
            }
        }

        return response()->json($item, 201);
    }

    /** Enable/disable a plugin or reorder it (briefing 15. — admin only, see routes/api.php). */
    public function updatePlugin(Request $request, MetadataPlugin $plugin)
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer'],
            'config' => ['sometimes', 'array'],
        ]);

        $plugin->update($data);

        return $plugin;
    }
}
