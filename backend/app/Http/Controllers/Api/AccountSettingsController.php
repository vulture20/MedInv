<?php

namespace App\Http\Controllers\Api;

use App\Domain\Users\UserDeletionService;
use App\Http\Controllers\Controller;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * The logged-in user's own preferences (briefing 4.1: "benutzerdefinierte
 * Einstellungen", e.g. preferred template/language, editable from the user
 * menu). Distinct from UserController, which is admin-only account
 * management of *other* users — destroy() below is this controller's one
 * exception, letting a user manage their *own* account's lifecycle rather
 * than just its preferences (GitHub issue #86).
 */
class AccountSettingsController extends Controller
{
    public function __construct(private readonly UserDeletionService $userDeletionService) {}

    public function update(Request $request)
    {
        // GitHub issue #11: 'light'/'dark' (the two bundled templates,
        // frontend/src/index.css) plus any code with a `templates` row —
        // admin-added or one of the repo-shipped templates/*.json files
        // (BundledTemplateRegistry), it makes no difference here, since
        // both end up as the same kind of row. Computed fresh on every
        // request rather than cached, same reasoning as
        // AdminSettingsController::updateLocale()'s identical fix: this
        // setting is small and rarely changed, so the extra query per save
        // isn't worth optimizing away, and a since-deleted template can't
        // still be picked.
        $allowedTemplates = [...['light', 'dark'], ...Template::query()->pluck('code')->all()];

        $data = $request->validate([
            'preferred_language' => ['sometimes', 'string', 'max:10'],
            'preferred_template' => ['sometimes', Rule::in($allowedTemplates)],
        ]);

        $user = $request->user();
        $user->update($data);

        return $user;
    }

    /**
     * Self-service account deletion (GitHub issue #86, a privacy-review
     * finding this session — the right of erasure — rather than a briefing
     * chapter): previously only an admin could remove an account at all
     * (UserController::destroy()) — a user who no longer wants to use the
     * application had no way to remove their own data without asking one.
     * Enforces the exact same two rules that path does (UserDeletionService)
     * — the predefined admin account can't delete itself either, and an
     * account still owning libraries must transfer ownership or delete
     * them first, same as an admin would have to do on that account's
     * behalf.
     *
     * Logs the account out *before* deleting it — same sequence
     * AuthController::logout() uses, but the ordering here matters in a way
     * it doesn't there: SessionGuard::logout() cycles the user's remember-
     * me token, which calls $user->save() on the exact same model instance
     * $request->user() already resolved (confirmed live via
     * spl_object_id()) — Eloquent's save() checks $this->exists to decide
     * insert vs. update, and delete() already flips that to false. Deleting
     * first and logging out after therefore didn't throw or no-op, it
     * silently *resurrected* the just-deleted row via a fresh INSERT
     * (same id, freshly-rotated remember_token) the instant logout() ran —
     * confirmed live by tracing the actual SQL log, not just by reasoning
     * about it. Logging out first avoids ever calling save() on a
     * non-existent row in the first place, so the eventual delete() below
     * is the one that actually sticks.
     */
    public function destroy(Request $request)
    {
        $user = $request->user();
        $blockingReason = $this->userDeletionService->blockingReasonFor($user);

        if ($blockingReason) {
            $this->logApiError($request, $blockingReason['error_code'], $blockingReason['message']);

            return response()->json($blockingReason, 422);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Log::info('User deleted (self-service)', ['user_id' => $user->id, 'email' => $user->email]);
        $user->delete();

        return response()->noContent();
    }
}
