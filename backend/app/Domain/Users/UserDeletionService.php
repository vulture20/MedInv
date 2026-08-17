<?php

namespace App\Domain\Users;

use App\Models\User;

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
}
