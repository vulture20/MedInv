<?php

namespace App\Domain\Libraries;

use App\Models\Library;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Central implementation of the visibility/access rules in briefing 4.2 and
 * 4.3, shared by LibraryController, SearchService and StatisticsService so
 * the rules live in exactly one place.
 *
 * Rules:
 * - Admins can read/write every library, regardless of shares.
 * - A library's owner can read/write it.
 * - Anyone else can only *read* it if a matching LibraryShare exists:
 *   scope=all_users (any "user"-level account), scope=guest (any
 *   "guest"-level account), or scope=user with their own user_id.
 * - Unshared libraries are invisible to non-owners/non-admins, including in
 *   search results (4.3: "weder sichtbar noch auffindbar").
 */
class LibraryAccessService
{
    public function canRead(User $user, Library $library): bool
    {
        if ($user->isAdmin() || $library->owner_id === $user->id) {
            return true;
        }

        return $library->shares()
            ->where(function (Builder $query) use ($user) {
                $query->where('scope', $user->isGuest() ? 'guest' : 'all_users')
                    ->orWhere(function (Builder $q) use ($user) {
                        $q->where('scope', 'user')->where('user_id', $user->id);
                    });
            })
            ->exists();
    }

    public function canWrite(User $user, Library $library): bool
    {
        return $user->isAdmin() || $library->owner_id === $user->id;
    }

    /** Query scoped to libraries visible to the given user (used for listing/search). */
    public function visibleLibrariesQuery(User $user): Builder
    {
        $query = Library::query();

        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('owner_id', $user->id)
                ->orWhereHas('shares', function (Builder $shareQuery) use ($user) {
                    $shareQuery->where('scope', $user->isGuest() ? 'guest' : 'all_users')
                        ->orWhere(function (Builder $sq) use ($user) {
                            $sq->where('scope', 'user')->where('user_id', $user->id);
                        });
                });
        });
    }
}
