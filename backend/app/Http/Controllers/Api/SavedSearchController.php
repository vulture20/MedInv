<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedSearch;
use Illuminate\Http\Request;

/**
 * GitHub issue #73's "nice to have": named, reusable search-mask filter
 * combinations. Purely personal — a saved search is never shared or
 * library-scoped, so this checks ownership itself (`abort_unless`, same
 * idiom LibraryController/MediaItemController use for their own
 * LibraryAccessService checks) rather than going through that service.
 */
class SavedSearchController extends Controller
{
    /** Every saved search belonging to the requesting user — never another user's, admin included; this is a personal bookmark list, not an admin-manageable resource. */
    public function index(Request $request)
    {
        return SavedSearch::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get(['id', 'name', 'filters']);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'filters' => ['required', 'array'],
        ]);

        return SavedSearch::query()->create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'filters' => $data['filters'],
        ]);
    }

    public function destroy(Request $request, SavedSearch $savedSearch)
    {
        abort_unless($savedSearch->user_id === $request->user()->id, 403);

        $savedSearch->delete();

        return response()->noContent();
    }
}
