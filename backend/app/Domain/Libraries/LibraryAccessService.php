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
 * - Anyone else can *read* it if a matching LibraryShare exists:
 *   scope=all_users (any "user"-level account), scope=guest (any
 *   "guest"-level account), or scope=user with their own user_id.
 * - A share can additionally grant write access to that library's *items*
 *   (not the library itself — see canWrite() vs. canWriteItems() below) via
 *   its `access_level` column (GitHub issue #79, a deliberate extension
 *   beyond briefing 4.3's original "jeweils mit Lesezugriff" — see the
 *   migration that added the column) — except scope=guest, which never
 *   grants write regardless of access_level (briefing 4.2: "Gast: ... Keine
 *   Anlage, keine Bearbeitung").
 * - Unshared libraries are invisible to non-owners/non-admins, including in
 *   search results (4.3: "weder sichtbar noch auffindbar").
 */
class LibraryAccessService
{
    public function canRead(User $user, Library $library): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        // Ownership alone isn't enough for a guest-level account (GitHub
        // issue #40) — the same reasoning as canWrite() below (issue #35):
        // UserController::update() allows demoting a user to guest at any
        // time without forcing an ownership transfer, so an account can
        // still technically own a library it's no longer allowed to see at
        // all per briefing 4.2 ("Gast: Kann ausschließlich Bibliotheken
        // lesen, die explizit für Gäste freigegeben wurden.") — a library
        // merely still owned by a demoted account was never "explizit für
        // Gäste freigegeben". A guest owner falls through to the share
        // check below like anyone else, so an explicit scope=guest share on
        // their own former library still works.
        if (! $user->isGuest() && $library->owner_id === $user->id) {
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

    /**
     * Whether the user can *manage* this library itself: rename/describe it
     * (LibraryController::update()), delete it, replace its share list, or
     * transfer its ownership. Deliberately admin-or-owner only, unaffected
     * by GitHub issue #79's write shares below — briefing 5. ("Bibliotheken
     * lassen sich durch ihren Ersteller oder einen Administrator löschen")
     * and the issue's own proposal both keep library-level management
     * exclusive to the owner/an admin, distinct from canWriteItems() below,
     * which a write share *does* extend to. If you're checking whether a
     * request can create/edit/delete a *media item* inside a library
     * (MediaItemController, CaptureController, MetadataController), you
     * want canWriteItems(), not this method.
     */
    public function canWrite(User $user, Library $library): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        // Ownership alone isn't enough (GitHub issue #35): a guest-level
        // account never has write access, even to a library it still owns
        // from before being demoted (UserController::update() allows
        // changing a user's level at any time, and there's no ownership
        // transfer forced on demotion). Without this check, this rule would
        // depend entirely on every write route staying correctly registered
        // under the `level:user,admin` middleware group in routes/api.php —
        // this makes it hold regardless of routing (briefing 4.2: "Gast:
        // ... Keine Anlage, keine Bearbeitung").
        return ! $user->isGuest() && $library->owner_id === $user->id;
    }

    /**
     * Whether the user can create/edit/delete this library's *items* —
     * everything canWrite() allows (an owner/admin can obviously also edit
     * items), plus GitHub issue #79's write shares: a scope=all_users or
     * scope=user LibraryShare with access_level='write' extends this to a
     * user who doesn't own the library and isn't an admin, without handing
     * them any of canWrite()'s library-management actions above. No
     * scope=guest branch here at all, unlike canRead() — a guest-level user
     * already returns false via canWrite() above (this method's first
     * check), and a non-guest user never matches a scope=guest share in the
     * first place (canRead() has the same asymmetry); combined,
     * scope=guest can never grant write access, matching briefing 4.2's
     * "Gast: ... Keine Anlage, keine Bearbeitung" regardless of
     * access_level — LibraryController::updateShares() also rejects
     * scope=guest combined with access_level=write at the source, but this
     * is the check that actually enforces it regardless of how the row got
     * there (e.g. a user demoted to guest after being granted a write
     * share, with no forced share cleanup on demotion — the same gap
     * canWrite() itself guards against for ownership, just for a write
     * share instead).
     */
    public function canWriteItems(User $user, Library $library): bool
    {
        if ($this->canWrite($user, $library)) {
            return true;
        }

        if ($user->isGuest()) {
            return false;
        }

        return $library->shares()
            ->where('access_level', 'write')
            ->where(function (Builder $query) use ($user) {
                $query->where('scope', 'all_users')
                    ->orWhere(function (Builder $q) use ($user) {
                        $q->where('scope', 'user')->where('user_id', $user->id);
                    });
            })
            ->exists();
    }

    /**
     * Same as visibleLibrariesQuery() above, additionally excluding any
     * library the requesting user has personally opted out of for one
     * LibraryUserPreference flag (GitHub issue #179 — see that model's own
     * docblock for why this replaced GitHub issue #176's global,
     * admin/owner-set Library columns). $preferenceColumn is one of
     * 'exclude_from_statistics'/'exclude_from_reports'/
     * 'exclude_from_dashboard' — StatisticsService/ReportsService/
     * SearchService::randomItemsFor() each pass their own. A library with
     * no LibraryUserPreference row at all for this user is never excluded
     * (whereDoesntHave() matches that case naturally), matching the
     * column's own former default of false.
     */
    public function visibleLibrariesQueryExcluding(User $user, string $preferenceColumn): Builder
    {
        return $this->visibleLibrariesQuery($user)->whereDoesntHave(
            'userPreferences',
            fn (Builder $q) => $q->where('user_id', $user->id)->where($preferenceColumn, true)
        );
    }

    /** Query scoped to libraries visible to the given user (used for listing/search). */
    public function visibleLibrariesQuery(User $user): Builder
    {
        $query = Library::query();

        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            // Same isGuest() exclusion as canRead() above (GitHub issue #40) —
            // ownership doesn't grant visibility to a demoted guest account,
            // only an explicit share does. orWhere() as the first call here
            // still works as a plain condition when isGuest() skips it.
            if (! $user->isGuest()) {
                $q->orWhere('owner_id', $user->id);
            }

            $q->orWhereHas('shares', function (Builder $shareQuery) use ($user) {
                $shareQuery->where('scope', $user->isGuest() ? 'guest' : 'all_users')
                    ->orWhere(function (Builder $sq) use ($user) {
                        $sq->where('scope', 'user')->where('user_id', $user->id);
                    });
            });
        });
    }
}
