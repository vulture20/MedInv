<?php

namespace App\Http\Controllers\Api;

use App\Domain\Libraries\LibraryAccessService;
use App\Http\Controllers\Controller;
use App\Models\Library;
use App\Models\LibraryUserPreference;
use Illuminate\Http\Request;

/**
 * GitHub issue #179: per-user exclude_from_statistics/exclude_from_reports/
 * exclude_from_dashboard preferences (see LibraryUserPreference's own
 * docblock for why this replaced GitHub issue #176's global, admin/owner-set
 * Library columns). Deliberately its own controller rather than folded into
 * LibraryController::update(): that action is canWrite()-gated (owner or
 * admin, briefing 5.) since it manages the library itself, whereas this is a
 * personal setting anyone who can merely *read* the library may set for
 * themselves — a guest with a shared library included.
 */
class LibraryPreferenceController extends Controller
{
    public function __construct(private readonly LibraryAccessService $access) {}

    /**
     * Every library visible to the requesting user, with their own
     * preference for it — defaulting every flag to false when no
     * LibraryUserPreference row exists yet (e.g. a library nobody has
     * touched this setting for, or one created after this feature shipped).
     * Feeds SettingsPage.tsx's own "Statistiken, Auswertungen & Startseite"
     * section, the one place a user manages this across every library at
     * once rather than per library.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $preferences = LibraryUserPreference::query()->where('user_id', $user->id)->get()->keyBy('library_id');

        return $this->access->visibleLibrariesQuery($user)
            ->orderBy('name')
            ->get()
            ->map(function (Library $library) use ($preferences) {
                $preference = $preferences->get($library->id);

                return [
                    'library_id' => $library->id,
                    'library_name' => $library->name,
                    'media_type' => $library->media_type,
                    'exclude_from_statistics' => $preference?->exclude_from_statistics ?? false,
                    'exclude_from_reports' => $preference?->exclude_from_reports ?? false,
                    'exclude_from_dashboard' => $preference?->exclude_from_dashboard ?? false,
                ];
            })
            ->values();
    }

    /** Upserts the requesting user's own preference row for one library — canRead(), not canWrite(), see this class's own docblock for why. */
    public function update(Request $request, Library $library)
    {
        abort_unless($this->access->canRead($request->user(), $library), 403);

        $data = $request->validate([
            'exclude_from_statistics' => ['sometimes', 'boolean'],
            'exclude_from_reports' => ['sometimes', 'boolean'],
            'exclude_from_dashboard' => ['sometimes', 'boolean'],
        ]);

        return LibraryUserPreference::query()->updateOrCreate(
            ['library_id' => $library->id, 'user_id' => $request->user()->id],
            $data
        );
    }
}
