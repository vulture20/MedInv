<?php

namespace App\Http\Controllers\Api;

use App\Domain\Libraries\LibraryAccessService;
use App\Http\Controllers\Controller;
use App\Models\Library;
use App\Models\LibraryShare;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Library CRUD and share management (briefing 5. + 4.3). `media_type` is
 * accepted on create only — there is deliberately no way to change it via
 * update(), matching "Die Medienart ist nachträglich nicht änderbar." (5.).
 */
class LibraryController extends Controller
{
    public function __construct(private readonly LibraryAccessService $access) {}

    public function index(Request $request)
    {
        return $this->access->visibleLibrariesQuery($request->user())->with('owner:id,name')->get();
    }

    public function show(Request $request, Library $library)
    {
        abort_unless($this->access->canRead($request->user(), $library), 403);

        return $library->load('owner:id,name', 'shares.user:id,name,email');
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

        $library->delete();

        return response()->noContent();
    }

    /** Replaces the full share list for a library (briefing 4.3). */
    public function updateShares(Request $request, Library $library)
    {
        abort_unless($this->access->canWrite($request->user(), $library), 403);

        $data = $request->validate([
            'shares' => ['array'],
            'shares.*.scope' => ['required_with:shares', Rule::in(['guest', 'all_users', 'user'])],
            'shares.*.user_id' => ['required_if:shares.*.scope,user', 'nullable', 'exists:users,id'],
        ]);

        foreach ($data['shares'] ?? [] as $share) {
            if ($share['scope'] === 'user' && empty($share['user_id'])) {
                throw ValidationException::withMessages(['shares' => 'user_id is required for scope=user.']);
            }
        }

        $library->shares()->delete();
        foreach ($data['shares'] ?? [] as $share) {
            LibraryShare::query()->create([
                'library_id' => $library->id,
                'scope' => $share['scope'],
                'user_id' => $share['scope'] === 'user' ? $share['user_id'] : null,
            ]);
        }

        return $library->load('shares.user:id,name,email');
    }
}
