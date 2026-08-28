<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MediaItemController::update(). Added alongside the media item detail
 * dialog's edit form: PUTting an explicit `null` for an optional field
 * (e.g. clearing "Description") used to 422 with "must be a string" —
 * the rule-rewriting for PUT (`array_slice($rule, 1)`) positionally
 * dropped whichever validation rule happened to be listed first, which for
 * every field except `title` is `nullable` itself, so it got silently
 * stripped. No prior UI ever sent an explicit null for these fields, so
 * this had gone unnoticed until confirmed live against a running dev
 * server while building that dialog.
 *
 * GitHub issue #201 added admin-only EAN editing to this same endpoint —
 * see the "admin-only EAN editing" section below. GitHub issue #202 then
 * made that editor itself admin-toggleable (`ean_editing.enabled`) — see
 * EanEditingSettingTest for that setting's own admin-settings coverage,
 * including MediaItemController::update() enforcing it server-side.
 */
class MediaItemUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_an_optional_field_can_be_cleared_by_sending_an_explicit_null(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create([
            'library_id' => $library->id,
            'title' => 'Dune',
            'ean' => '9780000000001',
            'description' => 'A desert planet epic.',
            'genre' => 'Sci-Fi',
        ]);

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", [
            'description' => null,
            'genre' => null,
        ]);

        $response->assertOk();
        $this->assertNull($item->fresh()->description);
        $this->assertNull($item->fresh()->genre);
    }

    public function test_fields_absent_from_the_request_are_left_untouched(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create([
            'library_id' => $library->id,
            'title' => 'Dune',
            'ean' => '9780000000001',
            'authors' => 'Frank Herbert',
        ]);

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['title' => 'Dune (Deluxe Edition)']);

        $response->assertOk();
        $this->assertSame('Dune (Deluxe Edition)', $item->fresh()->title);
        $this->assertSame('Frank Herbert', $item->fresh()->authors);
    }

    /** GitHub issue #201: EAN editing is admin-only — a plain owner/write-share user sending 'ean' still has it silently dropped, exactly as before that issue. */
    public function test_ean_cannot_be_changed_via_update_by_a_non_admin_owner(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['ean' => '9780000000099']);

        $response->assertOk();
        $this->assertSame('9780000000001', $item->fresh()->ean);
    }

    // --- GitHub issue #201: admin-only EAN editing ---

    public function test_an_admin_can_change_the_ean_to_a_new_value(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $this->actingAsUser('admin');

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['ean' => '9780000000099']);

        $response->assertOk();
        $this->assertSame('9780000000099', $item->fresh()->ean);
    }

    /** Omitting 'ean' entirely is "keep the current one" — the dialog's default option — even for an admin. */
    public function test_an_admin_omitting_ean_leaves_it_unchanged(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $this->actingAsUser('admin');

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['title' => 'Dune (renamed)']);

        $response->assertOk();
        $this->assertSame('9780000000001', $item->fresh()->ean);
    }

    /** An admin re-sending the item's own, unchanged EAN must not be treated as a self-collision. */
    public function test_an_admin_resending_the_items_own_current_ean_is_not_a_duplicate(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $this->actingAsUser('admin');

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['ean' => '9780000000001']);

        $response->assertOk();
        $this->assertSame('9780000000001', $item->fresh()->ean);
    }

    /**
     * GitHub issue #201's explicit requirement: the per-library duplicate
     * check runs before the change (or anything else in the same request)
     * is applied — a rejected EAN change must not leave the *other* fields
     * of the same request half-applied.
     */
    public function test_an_admin_changing_the_ean_to_one_already_used_in_the_library_is_rejected(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Existing', 'ean' => '9780000000002']);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $this->actingAsUser('admin');

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", [
            'title' => 'Should not be saved either',
            'ean' => '9780000000002',
        ]);

        $response->assertStatus(409);
        $fresh = $item->fresh();
        $this->assertSame('9780000000001', $fresh->ean);
        $this->assertSame('Dune', $fresh->title);
    }

    /** The exact same EAN is allowed across two different libraries (briefing 5.1) — a duplicate elsewhere never blocks this one. */
    public function test_an_admin_changing_the_ean_to_one_used_only_in_a_different_library_is_allowed(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $libraryA = Library::query()->create(['name' => 'Novels A', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $libraryB = Library::query()->create(['name' => 'Novels B', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $libraryB->id, 'title' => 'Elsewhere', 'ean' => '9780000000002']);
        $item = MediaBook::query()->create(['library_id' => $libraryA->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $this->actingAsUser('admin');

        $response = $this->putJson("/api/libraries/{$libraryA->id}/items/{$item->id}", ['ean' => '9780000000002']);

        $response->assertOk();
        $this->assertSame('9780000000002', $item->fresh()->ean);
    }

    /**
     * The dialog's third option: fall back to the NoEAN placeholder
     * mechanism (GitHub issue #151) — sending an empty ean, the same
     * "blank means generate one" convention store() already uses for a
     * brand-new capture.
     */
    public function test_an_admin_can_regenerate_a_noean_placeholder_by_sending_an_empty_ean(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $this->actingAsUser('admin');

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['ean' => '']);

        $response->assertOk();
        $this->assertStringStartsWith('NoEAN-', $item->fresh()->ean);
    }

    public function test_a_number_field_can_also_be_cleared_via_null(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'page_count' => 412]);

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['page_count' => null]);

        $response->assertOk();
        $this->assertNull($item->fresh()->page_count);
    }

    /**
     * GitHub issue #90: manually editing a CD's track list via this same
     * endpoint (frontend/src/pages/libraries/MediaItemDetailDialog.tsx's new
     * track editor) must re-derive runtime_seconds/runtime_computed from the
     * edited tracks — MediaItemService::withDerivedRuntime(), previously
     * only reached via create()/updateFromMetadata(), not a plain manual
     * edit.
     */
    public function test_editing_tracks_recomputes_runtime_when_runtime_seconds_is_not_also_sent(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'CDs', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        $item = MediaCd::query()->create([
            'library_id' => $library->id,
            'title' => 'OK Computer',
            'ean' => '724385522925',
            'tracks' => [['position' => '1', 'title' => 'Airbag', 'duration_seconds' => 284]],
            'runtime_seconds' => 284,
            'runtime_computed' => true,
        ]);

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", [
            'tracks' => [
                ['position' => '1', 'title' => 'Airbag', 'duration_seconds' => 284],
                ['position' => '2', 'title' => 'Paranoid Android', 'duration_seconds' => 383],
            ],
            'runtime_seconds' => null,
        ]);

        $response->assertOk();
        $this->assertSame(667, $item->fresh()->runtime_seconds);
        $this->assertTrue($item->fresh()->runtime_computed);
    }

    /** A runtime_seconds explicitly sent alongside tracks (a manual override) still wins, same as create()/updateFromMetadata(). */
    public function test_an_explicitly_sent_runtime_is_not_overwritten_even_when_tracks_are_also_edited(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'CDs', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        $item = MediaCd::query()->create([
            'library_id' => $library->id,
            'title' => 'OK Computer',
            'ean' => '724385522925',
            'tracks' => [['position' => '1', 'title' => 'Airbag', 'duration_seconds' => 284]],
        ]);

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", [
            'tracks' => [
                ['position' => '1', 'title' => 'Airbag', 'duration_seconds' => 284],
                ['position' => '2', 'title' => 'Paranoid Android', 'duration_seconds' => 383],
            ],
            'runtime_seconds' => 9999,
        ]);

        $response->assertOk();
        $this->assertSame(9999, $item->fresh()->runtime_seconds);
    }

    public function test_users_without_write_access_cannot_update(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $this->actingAsUser(); // a different, unrelated user — library is not shared with them

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['title' => 'Hijacked']);

        $response->assertForbidden();
        $this->assertSame('Dune', $item->fresh()->title);
    }
}
