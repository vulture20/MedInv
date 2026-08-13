<?php

namespace App\Http\Controllers\Api;

use App\Domain\Mail\MailStatusService;
use App\Http\Controllers\Controller;
use App\Mail\UserInvitationMail;
use App\Models\User;
use App\Rules\MedInvPasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Admin-only user management (briefing 4.1): accounts are created
 * exclusively by administrators (no self-signup). An account can be either
 * deactivated (login blocked, history/ownership preserved — see
 * deactivate()) or actually deleted (destroy()); the predefined admin
 * account (is_protected, see DatabaseSeeder) is exempt from both of those
 * AND from being edited, so an install can never end up without a working
 * admin account. All of the above is registered under
 * ->middleware('level:admin') in routes/api.php — the one exception is
 * shareable() below, which any non-guest user can call.
 */
class UserController extends Controller
{
    public function __construct(private readonly MailStatusService $mailStatus) {}

    public function index()
    {
        return User::query()->orderBy('name')->get();
    }

    /**
     * Minimal user list (id + name only, no email/level/is_active) for
     * populating the "share this library with a specific user" picker
     * (LibraryDetailPage.tsx, GitHub issue #32). Unlike index() above,
     * available to any non-guest user, not just admins — briefing 4.3 lets
     * a library's owner (not just an admin) manage its own shares, and
     * they need something to pick a target user from too. Excludes guest
     * accounts (4.3's "einzelne Benutzer" scope is specifically about
     * individual "Benutzer"-level accounts — guests are only ever covered
     * by the separate blanket "Gäste" scope) and the requesting user
     * themselves (sharing a library with yourself is meaningless).
     */
    public function shareable(Request $request)
    {
        return User::query()
            ->where('id', '!=', $request->user()->id)
            ->where('level', '!=', 'guest')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', new MedInvPasswordPolicy],
            'level' => ['required', Rule::in(['guest', 'user', 'admin'])],
            'send_invite' => ['sometimes', 'boolean'],
        ]);

        $sendInvite = (bool) ($data['send_invite'] ?? false);
        unset($data['send_invite']);

        $user = User::query()->create($data);
        $response = $user->toArray();

        if ($sendInvite) {
            [$response['invite_sent'], $response['invite_error']] = $this->sendInvite($request, $user);
        }

        return response()->json($response, 201);
    }

    /**
     * Best-effort: a failed invitation must never undo the account that was
     * just created (the admin can still hand out credentials manually, or
     * the frontend greys the checkbox out whenever mailServerHealthy is
     * false in the first place — see UsersPage.tsx). Mirrors
     * AdminSettingsController::testMail()'s not_configured/failure split.
     *
     * @return array{0: bool, 1: string|null}
     */
    private function sendInvite(Request $request, User $user): array
    {
        if (! $this->mailStatus->isConfigured()) {
            $this->logApiError($request, 'not_configured', 'Invitation mail skipped: mail server is not configured.');

            return [false, 'not_configured'];
        }

        try {
            Mail::to($user->email)->send(new UserInvitationMail($user));
        } catch (\Throwable $e) {
            $this->logApiError($request, 'invite_mail_failed', $e->getMessage());

            return [false, $e->getMessage()];
        }

        return [true, null];
    }

    public function update(Request $request, User $user)
    {
        if ($user->is_protected) {
            return $this->protectedAccountResponse($request, 'edited');
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
    public function deactivate(Request $request, User $user)
    {
        if ($user->is_protected) {
            return $this->protectedAccountResponse($request, 'deactivated');
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
    public function destroy(Request $request, User $user)
    {
        if ($user->is_protected) {
            return $this->protectedAccountResponse($request, 'deleted');
        }

        $user->delete();

        return response()->noContent();
    }

    /**
     * Shared 422 response for any write attempt against the predefined
     * admin account (edit, deactivate, delete). Uses an error_code rather
     * than only an English message, per CLAUDE.md's "API errors carry a
     * machine-readable error_code" convention — the frontend maps this to
     * one translated string regardless of which action was attempted. Also
     * logged with the requesting client's IP (Controller::logApiError()).
     */
    private function protectedAccountResponse(Request $request, string $action): JsonResponse
    {
        $message = "This account cannot be {$action}.";
        $this->logApiError($request, 'protected_account', $message);

        return response()->json([
            'error_code' => 'protected_account',
            'message' => $message,
        ], 422);
    }
}
