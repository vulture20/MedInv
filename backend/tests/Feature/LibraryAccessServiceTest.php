<?php

namespace Tests\Feature;

use App\Domain\Libraries\LibraryAccessService;
use App\Models\Library;
use App\Models\LibraryShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #33: LibraryAccessService (briefing 4.2/4.3) is the single,
 * central implementation of every library visibility/write-access rule in
 * the app — shared by LibraryController, SearchService and
 * StatisticsService — but had no dedicated test coverage at all. Covers
 * canRead()/canWrite()/visibleLibrariesQuery() directly; LibrarySharesTest
 * covers the same rules end-to-end through the actual HTTP endpoints
 * (updateShares(), and that an unshared library stays invisible in
 * search/statistics too).
 */
class LibraryAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $level): User
    {
        return User::factory()->create(['level' => $level, 'is_active' => true]);
    }

    private function library(User $owner): Library
    {
        return Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
    }

    private function share(Library $library, string $scope, ?User $user = null): LibraryShare
    {
        return LibraryShare::query()->create([
            'library_id' => $library->id,
            'scope' => $scope,
            'user_id' => $user?->id,
        ]);
    }

    // --- canRead() ---

    public function test_admin_can_read_any_library_without_any_share(): void
    {
        $library = $this->library($this->user('user'));

        $this->assertTrue(app(LibraryAccessService::class)->canRead($this->user('admin'), $library));
    }

    public function test_owner_can_read_their_own_library(): void
    {
        $owner = $this->user('user');
        $library = $this->library($owner);

        $this->assertTrue(app(LibraryAccessService::class)->canRead($owner, $library));
    }

    public function test_a_user_without_any_share_cannot_read_someone_elses_library(): void
    {
        $library = $this->library($this->user('user'));

        $this->assertFalse(app(LibraryAccessService::class)->canRead($this->user('user'), $library));
    }

    public function test_guest_scope_share_grants_read_to_guest_level_users(): void
    {
        $library = $this->library($this->user('user'));
        $this->share($library, 'guest');

        $this->assertTrue(app(LibraryAccessService::class)->canRead($this->user('guest'), $library));
    }

    public function test_guest_scope_share_does_not_grant_read_to_user_level_accounts(): void
    {
        $library = $this->library($this->user('user'));
        $this->share($library, 'guest');

        $this->assertFalse(app(LibraryAccessService::class)->canRead($this->user('user'), $library));
    }

    public function test_all_users_scope_share_grants_read_to_user_level_accounts(): void
    {
        $library = $this->library($this->user('user'));
        $this->share($library, 'all_users');

        $this->assertTrue(app(LibraryAccessService::class)->canRead($this->user('user'), $library));
    }

    public function test_all_users_scope_share_does_not_grant_read_to_guest_level_accounts(): void
    {
        $library = $this->library($this->user('user'));
        $this->share($library, 'all_users');

        $this->assertFalse(app(LibraryAccessService::class)->canRead($this->user('guest'), $library));
    }

    public function test_user_scope_share_grants_read_only_to_the_specifically_targeted_user(): void
    {
        $library = $this->library($this->user('user'));
        $targetUser = $this->user('user');
        $this->share($library, 'user', $targetUser);

        $this->assertTrue(app(LibraryAccessService::class)->canRead($targetUser, $library));
    }

    public function test_user_scope_share_does_not_grant_read_to_a_different_user(): void
    {
        $library = $this->library($this->user('user'));
        $this->share($library, 'user', $this->user('user'));

        $this->assertFalse(app(LibraryAccessService::class)->canRead($this->user('user'), $library));
    }

    /**
     * GitHub issue #40: canRead() used to grant access via ownership alone,
     * with no check of the user's current level — the same gap canWrite()
     * had before issue #35, just for reading instead of writing. An owner
     * demoted to "guest" (UserController::update() allows a level change at
     * any time, with no forced ownership transfer) would still pass
     * canRead() for their former library, even though it was never
     * "explizit für Gäste freigegeben" (briefing 4.2).
     */
    public function test_a_guest_level_owner_cannot_read_their_former_library_without_an_explicit_share(): void
    {
        $owner = $this->user('user');
        $library = $this->library($owner);
        $owner->update(['level' => 'guest']);

        $this->assertFalse(app(LibraryAccessService::class)->canRead($owner->fresh(), $library));
    }

    /** A guest owner still benefits from an explicit scope=guest share on their former library, same as any other guest. */
    public function test_a_guest_level_owner_can_read_their_former_library_if_it_is_also_guest_shared(): void
    {
        $owner = $this->user('user');
        $library = $this->library($owner);
        $this->share($library, 'guest');
        $owner->update(['level' => 'guest']);

        $this->assertTrue(app(LibraryAccessService::class)->canRead($owner->fresh(), $library));
    }

    // --- canWrite() ---

    public function test_admin_can_write_any_library(): void
    {
        $library = $this->library($this->user('user'));

        $this->assertTrue(app(LibraryAccessService::class)->canWrite($this->user('admin'), $library));
    }

    public function test_owner_can_write_their_own_library(): void
    {
        $owner = $this->user('user');
        $library = $this->library($owner);

        $this->assertTrue(app(LibraryAccessService::class)->canWrite($owner, $library));
    }

    /**
     * Sharing (4.3) is deliberately read-only — "jeweils mit Lesezugriff" —
     * regardless of scope. This is the single most security-relevant
     * assertion about this class: a share must never escalate into write
     * access for anyone but the owner/an admin.
     */
    public function test_no_share_scope_ever_grants_write_access(): void
    {
        $access = app(LibraryAccessService::class);
        $owner = $this->user('user');
        $library = $this->library($owner);
        $targetUser = $this->user('user');
        $this->share($library, 'guest');
        $this->share($library, 'all_users');
        $this->share($library, 'user', $targetUser);

        $this->assertFalse($access->canWrite($this->user('guest'), $library));
        $this->assertFalse($access->canWrite($this->user('user'), $library));
        $this->assertFalse($access->canWrite($targetUser, $library));
    }

    /**
     * GitHub issue #35: canWrite() used to check only ownership/admin
     * status, not the user's current level — so an owner demoted to
     * "guest" after creating a library (UserController::update() allows
     * changing level at any time, with no forced ownership transfer) would
     * still pass canWrite() for their own libraries. This is meant to hold
     * regardless of which routes/middleware groups happen to call it
     * (briefing 4.2: "Gast: ... Keine Anlage, keine Bearbeitung").
     */
    public function test_a_guest_level_owner_cannot_write_their_own_library(): void
    {
        $owner = $this->user('user');
        $library = $this->library($owner);
        $owner->update(['level' => 'guest']);

        $this->assertFalse(app(LibraryAccessService::class)->canWrite($owner->fresh(), $library));
    }

    // --- visibleLibrariesQuery() ---

    public function test_admin_sees_every_library_regardless_of_ownership_or_shares(): void
    {
        $this->library($this->user('user'));
        $this->library($this->user('user'));

        $visible = app(LibraryAccessService::class)->visibleLibrariesQuery($this->user('admin'))->get();

        $this->assertCount(2, $visible);
    }

    public function test_a_user_sees_their_own_and_every_share_scope_that_applies_to_them_but_not_an_unshared_library(): void
    {
        $viewer = $this->user('user');
        $owned = $this->library($viewer);
        $guestShared = $this->library($this->user('user'));
        $this->share($guestShared, 'guest');
        $allUsersShared = $this->library($this->user('user'));
        $this->share($allUsersShared, 'all_users');
        $userShared = $this->library($this->user('user'));
        $this->share($userShared, 'user', $viewer);
        $unshared = $this->library($this->user('user'));

        $visibleIds = app(LibraryAccessService::class)->visibleLibrariesQuery($viewer)->pluck('id');

        $this->assertTrue($visibleIds->contains($owned->id));
        $this->assertTrue($visibleIds->contains($allUsersShared->id));
        $this->assertTrue($visibleIds->contains($userShared->id));
        // guest-scope doesn't apply to a "user"-level viewer.
        $this->assertFalse($visibleIds->contains($guestShared->id));
        $this->assertFalse($visibleIds->contains($unshared->id));
    }

    public function test_a_guest_only_sees_guest_shared_libraries(): void
    {
        $viewer = $this->user('guest');
        $guestShared = $this->library($this->user('user'));
        $this->share($guestShared, 'guest');
        $allUsersShared = $this->library($this->user('user'));
        $this->share($allUsersShared, 'all_users');
        $unshared = $this->library($this->user('user'));

        $visibleIds = app(LibraryAccessService::class)->visibleLibrariesQuery($viewer)->pluck('id');

        $this->assertTrue($visibleIds->contains($guestShared->id));
        $this->assertFalse($visibleIds->contains($allUsersShared->id));
        $this->assertFalse($visibleIds->contains($unshared->id));
    }

    /** GitHub issue #40: same isGuest() gap as canRead(), for the query used by GET /libraries and search/statistics. */
    public function test_a_guest_level_owner_does_not_see_their_former_library_without_an_explicit_share(): void
    {
        $owner = $this->user('user');
        $library = $this->library($owner);
        $owner->update(['level' => 'guest']);

        $visibleIds = app(LibraryAccessService::class)->visibleLibrariesQuery($owner->fresh())->pluck('id');

        $this->assertFalse($visibleIds->contains($library->id));
    }
}
