<?php

namespace App\Http\Controllers\Api;

use App\Domain\Libraries\DuplicateEanException;
use App\Domain\Libraries\LibraryAccessService;
use App\Domain\Libraries\MediaItemService;
use App\Http\Controllers\Controller;
use App\Models\Library;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Media item CRUD, scoped to a single library and its fixed media_type
 * (briefing 6.). One controller for all three types rather than three
 * near-identical ones — the type-specific model/attributes are resolved via
 * MediaItemService::modelClassFor($library->media_type).
 */
class MediaItemController extends Controller
{
    public function __construct(
        private readonly LibraryAccessService $access,
        private readonly MediaItemService $mediaItemService,
    ) {}

    public function index(Request $request, Library $library)
    {
        abort_unless($this->access->canRead($request->user(), $library), 403);

        return $library->mediaItems()->paginate($request->integer('per_page', 50));
    }

    public function show(Request $request, Library $library, int $item)
    {
        abort_unless($this->access->canRead($request->user(), $library), 403);

        return $library->mediaItems()->findOrFail($item);
    }

    /**
     * Streams a stored cover image (see CoverDownloadService, briefing 8.3
     * step 5). Served through the API rather than a direct storage URL —
     * covers live on the `local` disk, not `public` (see
     * CoverDownloadService's docblock) — which also means this gets the
     * same LibraryAccessService::canRead() check every other read of this
     * library's items already goes through (4.3: unshared -> invisible).
     */
    public function cover(Request $request, Library $library, int $item)
    {
        abort_unless($this->access->canRead($request->user(), $library), 403);

        $record = $library->mediaItems()->findOrFail($item);

        abort_if(! $record->cover_path, 404);

        return Storage::disk('local')->response($record->cover_path);
    }

    /**
     * Single-entry capture (briefing 7.1). Duplicate EANs within the
     * library are strictly rejected (5.1) — no auto stock increase.
     */
    public function store(Request $request, Library $library)
    {
        abort_unless($this->access->canWrite($request->user(), $library), 403);

        $data = $request->validate($this->rulesFor($library->media_type));

        try {
            $item = $this->mediaItemService->create($library, $data);
        } catch (DuplicateEanException $e) {
            return response()->json(['message' => $e->getMessage(), 'ean' => $e->ean], 409);
        }

        return response()->json($item, 201);
    }

    public function update(Request $request, Library $library, int $item)
    {
        abort_unless($this->access->canWrite($request->user(), $library), 403);

        $record = $library->mediaItems()->findOrFail($item);
        $rules = $this->rulesFor($library->media_type);
        // EAN changes go through the same duplicate check as creation would; simplest to
        // disallow here and require delete+recreate, since briefing 5.1 focuses on capture.
        unset($rules['ean']);
        // Drop 'required' specifically (title is the only field that has it —
        // everything else in rulesFor() already starts with 'nullable') rather
        // than positionally slicing off index 0. array_slice($rule, 1) used to
        // sit here and silently discarded whichever rule happened to come
        // first — for every 'nullable' field (i.e. everything except title)
        // that meant discarding 'nullable' itself, so PUTting an explicit
        // `null` to clear an optional field (e.g. from the media item detail
        // dialog's edit form) 422ed with "must be a string" instead of
        // clearing it. Confirmed live against a running dev server while
        // building that dialog — no prior UI ever sent an explicit null for
        // these fields, so this had gone unnoticed.
        $data = $request->validate(array_map(
            fn ($rule) => ['sometimes', ...array_values(array_diff($rule, ['required']))],
            $rules
        ));

        $record->update($data);

        return $record;
    }

    public function destroy(Request $request, Library $library, int $item)
    {
        abort_unless($this->access->canWrite($request->user(), $library), 403);

        $library->mediaItems()->findOrFail($item)->delete();

        return response()->noContent();
    }

    /**
     * Moves a media item into a different library (media item detail
     * dialog's "move" action). Requires write access to *both* libraries —
     * moving something out of a library the user doesn't own/administer
     * would let them empty it out from under its actual owner, and moving
     * into one they can't write to would bypass that library's own write
     * protection entirely. Restricted to same-media_type libraries: the
     * record's model class only fits one specific media_type's table (see
     * MediaItemService::move()'s docblock), so this isn't just a policy
     * choice.
     */
    public function move(Request $request, Library $library, int $item)
    {
        abort_unless($this->access->canWrite($request->user(), $library), 403);

        $record = $library->mediaItems()->findOrFail($item);
        $data = $request->validate([
            'target_library_id' => ['required', 'integer', 'exists:'.(new Library)->getTable().',id'],
        ]);

        $target = Library::query()->findOrFail($data['target_library_id']);
        abort_unless($this->access->canWrite($request->user(), $target), 403);

        if ($target->id === $library->id) {
            return response()->json(['error_code' => 'same_library', 'message' => 'Item is already in this library.'], 422);
        }

        if ($target->media_type !== $library->media_type) {
            return response()->json(['error_code' => 'media_type_mismatch', 'message' => 'Target library has a different media type.'], 422);
        }

        try {
            $this->mediaItemService->move($record, $target);
        } catch (DuplicateEanException $e) {
            return response()->json(['message' => $e->getMessage(), 'ean' => $e->ean], 409);
        }

        return $record->fresh();
    }

    private function rulesFor(string $mediaType): array
    {
        return match ($mediaType) {
            'book' => [
                'title' => ['required', 'string', 'max:255'],
                'ean' => ['required', 'string', 'max:13'],
                'cover_path' => ['nullable', 'string'],
                'description' => ['nullable', 'string'],
                'authors' => ['nullable', 'string'],
                'format' => ['nullable', 'string'],
                'genre' => ['nullable', 'string'],
                'page_count' => ['nullable', 'integer'],
                'language' => ['nullable', 'string', 'max:10'],
                'publisher' => ['nullable', 'string'],
                'release_date' => ['nullable', 'date'],
                'price' => ['nullable', 'numeric'],
                'isbn10' => ['nullable', 'string', 'max:10'],
                'isbn13' => ['nullable', 'string', 'max:13'],
            ],
            'cd' => [
                'title' => ['required', 'string', 'max:255'],
                'ean' => ['required', 'string', 'max:13'],
                'cover_path' => ['nullable', 'string'],
                'description' => ['nullable', 'string'],
                'artist' => ['nullable', 'string'],
                'medium' => ['nullable', 'string'],
                'asin' => ['nullable', 'string'],
                'disc_count' => ['nullable', 'integer', 'min:1'],
                'release_date' => ['nullable', 'date'],
                'price' => ['nullable', 'numeric'],
            ],
            'dvd_bluray' => [
                'title' => ['required', 'string', 'max:255'],
                'ean' => ['required', 'string', 'max:13'],
                'cover_path' => ['nullable', 'string'],
                'description' => ['nullable', 'string'],
                'medium' => ['nullable', 'string'],
                'disc_count' => ['nullable', 'integer', 'min:1'],
                'runtime_minutes' => ['nullable', 'integer'],
                'languages' => ['nullable', 'string'],
                'cast' => ['nullable', 'string'],
                'director' => ['nullable', 'string'],
                'release_date' => ['nullable', 'date'],
                'production_year' => ['nullable', 'integer'],
                'price' => ['nullable', 'numeric'],
            ],
        };
    }
}
