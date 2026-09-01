<?php

namespace App\Http\Controllers\Api;

use App\Domain\Mail\MailStatusService;
use App\Domain\Users\UserDeletionService;
use App\Http\Controllers\Controller;
use App\Mail\UserInvitationMail;
use App\Models\User;
use App\Rules\MedInvPasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
    public function __construct(
        private readonly MailStatusService $mailStatus,
        private readonly UserDeletionService $userDeletionService,
    ) {}

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

    /**
     * GitHub issue #198 (user-reported, following #197's own root cause):
     * `email` is validated for format only here, not uniqueness — a
     * duplicate address is checked explicitly below instead of via
     * Laravel's `unique` rule, so it gets its own translated `email_taken`
     * error_code (adminErrors.ts's describeError()) rather than Laravel's
     * raw, untranslated "The email has already been taken." leaking
     * through describeError()'s generic validation-error fallback — a
     * completely ordinary admin scenario (a typo, or genuinely re-adding
     * an existing account), not a rare edge case.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'password' => ['required', new MedInvPasswordPolicy],
            'level' => ['required', Rule::in(['guest', 'user', 'admin'])],
            'send_invite' => ['sometimes', 'boolean'],
        ]);

        if (User::query()->where('email', $data['email'])->exists()) {
            return $this->errorResponse($request, 'email_taken', 'This email address is already in use.');
        }

        $sendInvite = (bool) ($data['send_invite'] ?? false);
        unset($data['send_invite']);

        $user = User::query()->create($data);
        $response = $user->toArray();

        // Admin account management audit trail — who created which account,
        // at what level, previously not logged at all (only the failure
        // paths below — a protected-account edit, an invite that failed to
        // send — were).
        Log::info('User created', ['actor_id' => $request->user()->id, 'user_id' => $user->id, 'email' => $user->email, 'level' => $user->level]);

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

    /**
     * `password` (GitHub issue #175) lets an admin set another account's
     * password directly — previously the only way to change a password
     * after account creation at all was the email-based "forgot password"
     * flow (also needing a configured mail server), or the user's own
     * self-service change (AccountSettingsController::updatePassword(),
     * GitHub issue #174). Deliberately `sometimes` and never required, the
     * same "leave blank to keep the current one" shape
     * AdminSettingsController::updateMail()'s SMTP password field already
     * established (MailPage.tsx only sends the key at all when its field
     * is non-empty) — UsersPage.tsx's edit row follows the identical
     * convention. Unlike AccountSettingsController::updatePassword(),
     * there is no current-password check here at all: an admin editing
     * another account already has strictly broader unchecked power over
     * it (create, delete, deactivate, change its email/level), so a
     * password set this way is just one more of those, not a new kind of
     * privilege — and this is also the intended way to restore access to
     * an OIDC-provisioned account (see AccountSettingsController::
     * updatePassword()'s own docblock), which has no current password its
     * owner could ever supply in the first place.
     */
    public function update(Request $request, User $user)
    {
        if ($user->is_protected) {
            return $this->protectedAccountResponse($request, 'edited');
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email'],
            'level' => ['sometimes', Rule::in(['guest', 'user', 'admin'])],
            'password' => ['sometimes', 'string', new MedInvPasswordPolicy],
        ]);

        // GitHub issue #198 — see store()'s own docblock for why this is a
        // manual check rather than a `unique` validation rule.
        if (isset($data['email']) && User::query()->where('email', $data['email'])->where('id', '!=', $user->id)->exists()) {
            return $this->errorResponse($request, 'email_taken', 'This email address is already in use.');
        }

        $user->update($data);

        // $data only ever contains fields the request actually sent
        // ('sometimes' rules) — logging it shows exactly what an admin
        // changed, `level` above all: a level change is a privilege
        // change. `password` is redacted first — the same "never let a
        // credential change reach the log in the clear" rule
        // AdminSettingsController::logSettingsChange() already applies to
        // its own password/client_secret fields.
        $logged = $data;
        if (array_key_exists('password', $logged)) {
            $logged['password'] = '[REDACTED]';
        }
        Log::info('User updated', ['actor_id' => $request->user()->id, 'user_id' => $user->id, 'changes' => $logged]);

        return $user;
    }

    /** Deactivates without deleting (briefing 4.1) — login becomes impossible, history is kept. See destroy() to actually remove the account. */
    public function deactivate(Request $request, User $user)
    {
        if ($user->is_protected) {
            return $this->protectedAccountResponse($request, 'deactivated');
        }

        $user->update(['is_active' => false]);
        Log::info('User deactivated', ['actor_id' => $request->user()->id, 'user_id' => $user->id, 'email' => $user->email]);

        return $user;
    }

    public function reactivate(Request $request, User $user)
    {
        $user->update(['is_active' => true]);
        Log::info('User reactivated', ['actor_id' => $request->user()->id, 'user_id' => $user->id, 'email' => $user->email]);

        return $user;
    }

    /**
     * Unlike deactivate(), this actually removes the account. See
     * UserDeletionService's docblock for the two rules this enforces
     * (predefined-admin exemption, still-owns-libraries rejection) — shared
     * with AccountSettingsController::destroy()'s self-service counterpart
     * (GitHub issue #86).
     */
    public function destroy(Request $request, User $user)
    {
        $blockingReason = $this->userDeletionService->blockingReasonFor($user);

        if ($blockingReason) {
            $this->logApiError($request, $blockingReason['error_code'], $blockingReason['message']);

            return response()->json($blockingReason, 422);
        }

        Log::info('User deleted', ['actor_id' => $request->user()->id, 'user_id' => $user->id, 'email' => $user->email]);
        // GitHub issue #222: purges the user's other sessions alongside the account itself.
        $this->userDeletionService->delete($user);

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
