<?php

namespace App\Http\Controllers\Api;

use App\Domain\Libraries\DuplicateEanException;
use App\Domain\Libraries\LibraryAccessService;
use App\Domain\Libraries\MediaItemService;
use App\Domain\Metadata\MetadataImportService;
use App\Http\Controllers\Controller;
use App\Models\Library;
use App\Models\MetadataPlugin;
use Illuminate\Http\Request;

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
            'attributes.ean' => ['required', 'string'],
            'cover_url' => ['nullable', 'string'], // TODO: download + store under storage/app/covers, see 8.3 step 5.
        ]);

        try {
            $item = $this->mediaItemService->create($library, $data['attributes']);
        } catch (DuplicateEanException $e) {
            return response()->json(['message' => $e->getMessage(), 'ean' => $e->ean], 409);
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
