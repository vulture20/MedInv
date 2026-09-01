<?php

namespace App\Domain\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The two rules that must hold before any User row is actually deleted —
 * shared between UserController::destroy() (an admin deleting another
 * account) and AccountSettingsController::destroy() (self-service, GitHub
 * issue #86), so both trigger paths enforce exactly the same restrictions
 * instead of one silently drifting out of sync with the other:
 *
 * - The predefined admin account (is_protected, see DatabaseSeeder) is
 *   exempt, so an install can never end up without a working admin
 *   account — including from deleting itself.
 * - An account that still owns libraries is rejected (GitHub issue #34) —
 *   a library can be shared with *other* users/guests (briefing 4.3), so
 *   deleting its owner would otherwise silently take it away from
 *   everyone else it was shared with (libraries.owner_id is
 *   restrictOnDelete() at the database level too, not just here — this is
 *   what turns that hard DB error into a friendly, actionable one). The
 *   account holder (or an admin acting on their behalf) must transfer
 *   ownership first (LibraryController::transferOwnership()) or delete
 *   those libraries deliberately.
 */
class UserDeletionService
{
    /**
     * @return array{error_code: string, message: string, libraries?: array}|null Null when $user can be deleted outright.
     */
    public function blockingReasonFor(User $user): ?array
    {
        if ($user->is_protected) {
            return ['error_code' => 'protected_account', 'message' => 'This account cannot be deleted.'];
        }

        $ownedLibraries = $user->ownedLibraries()->get(['id', 'name']);

        if ($ownedLibraries->isNotEmpty()) {
            return [
                'error_code' => 'owns_libraries',
                'message' => 'This account still owns libraries and cannot be deleted until ownership is transferred or they are deleted.',
                'libraries' => $ownedLibraries->toArray(),
            ];
        }

        return null;
    }

    /**
     * The actual deletion, shared by both trigger paths (call this instead
     * of `$user->delete()` directly) so a stale `sessions` row for this
     * user can't outlive the account either way — GitHub issue #222, a
     * privacy-review finding: unlike every other user-referencing table in
     * this app (`library_shares`/`saved_searches`/`library_user_preferences`
     * all `cascadeOnDelete()`, `captured_by_user_id` `nullOnDelete()`),
     * Laravel's stock `sessions` table (created unconditionally by the base
     * users-table migration, live whenever `SESSION_DRIVER=database` — this
     * app's own default, see `.env.example`/`config/session.php`) carries
     * no FK constraint on `user_id` at all. A deleted account's *other*
     * active sessions (e.g. still logged in on a second device/browser —
     * `AccountSettingsController::destroy()`'s own session invalidation
     * only ever covers the *current* one, and `UserController::destroy()`
     * has no "current session" to invalidate in the first place) previously
     * persisted — each row carrying `ip_address`/`user_agent`, both
     * personal data — until Laravel's own probabilistic session
     * garbage-collection (`config/session.php`'s `lottery`) happened to
     * sweep them, which can take a long time on a low-traffic instance.
     * Not a live security issue (a deleted user's session can no longer
     * resolve to a real account regardless — `EloquentUserProvider`
     * re-resolves by ID on every request), but a real data-minimization
     * gap. Deleted explicitly here rather than left to that lottery, the
     * same "gone before it can be orphaned" treatment every other
     * personal-data-bearing table above already gets via DB-level cascade.
     */
    public function delete(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->delete();
    }
}
