<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\MedInvPasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-only user management (briefing 4.1): accounts are created
 * exclusively by administrators (no self-signup). An account can be either
 * deactivated (login blocked, history/ownership preserved — see
 * deactivate()) or actually deleted (destroy()); the predefined admin
 * account (is_protected, see DatabaseSeeder) is exempt from both of those
 * AND from being edited, so an install can never end up without a working
 * admin account. Registered under ->middleware('level:admin') in
 * routes/api.php.
 */
class UserController extends Controller
{
    public function index()
    {
        return User::query()->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', new MedInvPasswordPolicy],
            'level' => ['required', Rule::in(['guest', 'user', 'admin'])],
        ]);

        return response()->json(User::query()->create($data), 201);
    }

    public function update(Request $request, User $user)
    {
        if ($user->is_protected) {
            return $this->protectedAccountResponse('edited');
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'level' => ['sometimes', Rule::in(['guest', 'user', 'admin'])],
        ]);

        $user->update($data);

        return $user;
    }

    /** Deactivates without deleting (briefing 4.1) — login becomes impossible, history is kept. See destroy() to actually remove the account. */
    public function deactivate(User $user)
    {
        if ($user->is_protected) {
            return $this->protectedAccountResponse('deactivated');
        }

        $user->update(['is_active' => false]);

        return $user;
    }

    public function reactivate(User $user)
    {
        $user->update(['is_active' => true]);

        return $user;
    }

    /**
     * Unlike deactivate(), this actually removes the account — the
     * predefined admin (is_protected, see DatabaseSeeder) is exempt so an
     * install can never be left without any admin account.
     */
    public function destroy(User $user)
    {
        if ($user->is_protected) {
            return $this->protectedAccountResponse('deleted');
        }

        $user->delete();

        return response()->noContent();
    }

    /**
     * Shared 422 response for any write attempt against the predefined
     * admin account (edit, deactivate, delete). Uses an error_code rather
     * than only an English message, per CLAUDE.md's "API errors carry a
     * machine-readable error_code" convention — the frontend maps this to
     * one translated string regardless of which action was attempted.
     */
    private function protectedAccountResponse(string $action): JsonResponse
    {
        return response()->json([
            'error_code' => 'protected_account',
            'message' => "This account cannot be {$action}.",
        ], 422);
    }
}
