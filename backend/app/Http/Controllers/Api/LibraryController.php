<?php

namespace App\Http\Controllers\Api;

use App\Domain\ExportPdf\PdfExportService;
use App\Domain\Libraries\LibraryAccessService;
use App\Domain\Libraries\MediaItemService;
use App\Http\Controllers\Controller;
use App\Models\Library;
use App\Models\LibraryShare;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Library CRUD and share management (briefing 5. + 4.3). `media_type` is
 * accepted on create only — there is deliberately no way to change it via
 * update(), matching "Die Medienart ist nachträglich nicht änderbar." (5.).
 */
class LibraryController extends Controller
{
    public function __construct(
        private readonly LibraryAccessService $access,
        private readonly PdfExportService $pdfExportService,
    ) {}

    /**
     * GitHub issue #95: each library's item count, for LibrariesPage.tsx's
     * overview cards — direct `withCount('mediaItems')` doesn't work here,
     * since Library::mediaItems() only resolves the right relation at
     * runtime from an already-loaded $this->media_type, but withCount()
     * needs a relation it can resolve on an empty model instance while
     * building the query. Same three-relations-then-pick-one-per-row
     * pattern StatisticsService::overviewFor() already uses for exactly
     * this reason. The three raw *_count columns withCount() adds are
     * hidden from the response — only the single, already-correct
     * item_count is meant to be consumed here.
     */
    public function index(Request $request)
    {
        return $this->access->visibleLibrariesQuery($request->user())
            ->with('owner:id,name')
            ->withCount(['mediaBooks', 'mediaCds', 'mediaDvdBlurays'])
            ->get()
            ->each(function (Library $library) {
                $library->setAttribute('item_count', match ($library->media_type) {
                    'book' => $library->media_books_count,
                    'cd' => $library->media_cds_count,
                    'dvd_bluray' => $library->media_dvd_blurays_count,
                });
                $library->makeHidden(['media_books_count', 'media_cds_count', 'media_dvd_blurays_count']);
            });
    }

    /**
     * GitHub issue #39: the full share list — including the name/email of
     * anyone individually targeted by a scope=user share — is only for
     * whoever manages sharing (owner/admin, same gate as updateShares()
     * below and canManage in LibraryDetailPage.tsx). A plain reader who can
     * merely see the library via an all_users/guest/user share has no
     * business learning who else it's shared with, so `shares` is omitted
     * from the response entirely rather than redacted per-entry.
     */
    public function show(Request $request, Library $library)
    {
        abort_unless($this->access->canRead($request->user(), $library), 403);

        $library->load('owner:id,name');

        if ($this->access->canWrite($request->user(), $library)) {
            $library->load('shares.user:id,name,email');
        }

        return $library;
    }

    /**
     * A single library's contents as a printable/archivable PDF inventory
     * list (GitHub issue #87) — same read gate as show()/the item routes
     * above (LibraryAccessService::canRead()), a plain read action rather
     * than a management one, so a guest with an explicitly shared library
     * (briefing 4.2) can export it too, same as they can already browse it.
     *
     * `sort_by`/`sort_dir` (GitHub issue #128, the same fix #127 already
     * made for search's own PDF export) — validated against exactly the
     * same per-media-type whitelist MediaItemController::index()'s own
     * server-side item sort already uses, so the exported row order can
     * match whatever LibraryDetailPage.tsx's table is currently sorted by,
     * whether that's a column the admin explicitly clicked or the table's
     * own unsorted default.
     */
    public function exportPdf(Request $request, Library $library)
    {
        abort_unless($this->access->canRead($request->user(), $library), 403);

        $data = $request->validate([
            'sort_by' => ['nullable', Rule::in(MediaItemService::SORTABLE_COLUMNS[$library->media_type])],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $pdf = $this->pdfExportService->libraryInventoryPdf($library, $request->user(), $data['sort_by'] ?? null, $data['sort_dir'] ?? 'asc');
        $filename = 'medinv-'.$this->sanitizeForFilename($library->name).'-'.SystemSetting::localNow()->format('Ymd-His').'.pdf';

        return $pdf->download($filename);
    }

    /** Guests cannot create libraries (briefing 4.2) — enforced via ->middleware('level:user,admin'). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'media_type' => ['required', Rule::in(['book', 'cd', 'dvd_bluray'])],
        ]);

        return response()->json(
            Library::query()->create([...$data, 'owner_id' => $request->user()->id]),
            201
        );
    }

    public function update(Request $request, Library $library)
    {
        abort_unless($this->access->canWrite($request->user(), $library), 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $library->update($data);

        return $library;
    }

    public function destroy(Request $request, Library $library)
    {
        abort_unless($this->access->canWrite($request->user(), $library), 403);

        // Destructive-admin-action audit trail, previously not logged at all
        // — item_count captured before the cascade delete removes them, so
        // the log entry still says how much was actually lost.
        Log::info('Library deleted', [
            'actor_id' => $request->user()->id,
            'library_id' => $library->id,
            'name' => $library->name,
            'owner_id' => $library->owner_id,
            'item_count' => $library->mediaItems()->count(),
        ]);

        $library->delete();

        return response()->noContent();
    }

    /**
     * Transfers ownership of a library to another user (GitHub issue #34).
     * Same permission rule as every other library-write action: the
     * current owner or an admin — briefing 4.3 doesn't otherwise say who
     * manages a library, and this is squarely that same "manage" bucket.
     * The one thing this makes possible that update()/destroy() don't:
     * moving a library out from under an account before deleting it (see
     * UserController::destroy()'s rejection whenever the account still
     * owns libraries, added alongside this).
     */
    public function transferOwnership(Request $request, Library $library)
    {
        abort_unless($this->access->canWrite($request->user(), $library), 403);

        $data = $request->validate(['owner_id' => ['required', 'integer', Rule::exists('users', 'id')]]);

        $newOwner = User::query()->findOrFail($data['owner_id']);

        // A guest can't manage libraries at all (briefing 4.2) — making one the
        // *owner* of a library would leave it with no one able to write to it
        // except an admin.
        if ($newOwner->isGuest()) {
            throw ValidationException::withMessages(['owner_id' => 'The new owner cannot be a guest-level account.']);
        }

        Log::info('Library ownership transferred', [
            'actor_id' => $request->user()->id,
            'library_id' => $library->id,
            'name' => $library->name,
            'previous_owner_id' => $library->owner_id,
            'new_owner_id' => $newOwner->id,
        ]);

        $library->update(['owner_id' => $newOwner->id]);

        return $library->load('owner:id,name', 'shares.user:id,name,email');
    }

    /** Replaces the full share list for a library (briefing 4.3). */
    public function updateShares(Request $request, Library $library)
    {
        abort_unless($this->access->canWrite($request->user(), $library), 403);

        $data = $request->validate([
            'shares' => ['array'],
            'shares.*.scope' => ['required_with:shares', Rule::in(['guest', 'all_users', 'user'])],
            'shares.*.user_id' => ['required_if:shares.*.scope,user', 'nullable', 'exists:users,id'],
            // GitHub issue #79: an optional write grant alongside the existing
            // read-only scope, deliberately beyond briefing 4.3's original
            // "jeweils mit Lesezugriff" — see the migration that added this
            // column. 'sometimes' + the access_level(...) default below rather
            // than 'required_with:shares', so a plain {scope, user_id} payload
            // from before this field existed (or a client that just doesn't
            // send it) still round-trips as a read-only share exactly like
            // before.
            'shares.*.access_level' => ['sometimes', Rule::in(['read', 'write'])],
        ]);

        foreach ($data['shares'] ?? [] as $share) {
            if ($share['scope'] === 'user' && empty($share['user_id'])) {
                throw ValidationException::withMessages(['shares' => 'user_id is required for scope=user.']);
            }
            // A guest-level account never has write access at all (briefing
            // 4.2, GitHub issue #35) — LibraryAccessService::canWriteItems()
            // already ignores access_level=write on a scope=guest share as a
            // second line of defense, but rejecting it here means whoever set
            // this up finds out immediately instead of assuming it took
            // effect.
            if (($share['access_level'] ?? 'read') === 'write' && $share['scope'] === 'guest') {
                throw ValidationException::withMessages(['shares' => 'A guest share cannot grant write access.']);
            }
        }

        $library->shares()->delete();
        foreach ($data['shares'] ?? [] as $share) {
            LibraryShare::query()->create([
                'library_id' => $library->id,
                'scope' => $share['scope'],
                'user_id' => $share['scope'] === 'user' ? $share['user_id'] : null,
                'access_level' => $share['access_level'] ?? 'read',
            ]);
        }

        // Who can see (and now, per share, write to — GitHub issue #79) a
        // library changing is an access-control change, same audit-trail
        // category as the ownership transfer above — this replaces the
        // *entire* share list every time (not an incremental add/remove), so
        // the log reflects the new state in full rather than a diff.
        Log::info('Library shares updated', [
            'actor_id' => $request->user()->id,
            'library_id' => $library->id,
            'guest' => collect($data['shares'] ?? [])->contains('scope', 'guest'),
            'all_users' => collect($data['shares'] ?? [])->contains('scope', 'all_users'),
            'user_ids' => collect($data['shares'] ?? [])->where('scope', 'user')->pluck('user_id')->all(),
            'write_all_users' => collect($data['shares'] ?? [])->contains(fn ($s) => $s['scope'] === 'all_users' && ($s['access_level'] ?? 'read') === 'write'),
            'write_user_ids' => collect($data['shares'] ?? [])->where('scope', 'user')->where('access_level', 'write')->pluck('user_id')->all(),
        ]);

        return $library->load('shares.user:id,name,email');
    }
}
