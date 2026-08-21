<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaCd;
use App\Models\MediaDvdBluray;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #155, reported by the user: capturing a CD/DVD/Blu-ray
 * without an EAN (issue #151) reliably failed with the app's generic
 * "Anmeldung fehlgeschlagen..." fallback error and no item was ever
 * created. Root-caused via the real deployment's own production log to a
 * SQLSTATE[23000] NOT NULL constraint violation on `disc_count`: that
 * column is NOT NULL with a DB-level default of 1 (see the
 * create_media_cds/create_media_dvd_blurays_table migrations), but the
 * default only applies when the column is *omitted* from the
 * INSERT/UPDATE — not when it's explicitly `null`, which is exactly what
 * a blank frontend form field (`payloadFromValues()`) or a metadata
 * candidate that doesn't report a disc count (confirmed real for the JPC
 * and every LLM-backed provider, see e.g. DiscogsProviderTest/
 * JpcDvdBlurayProviderTest) always sends. Not specific to the no-EAN
 * capture flow at all — any manual entry, metadata import/refresh, or
 * bulk-update that leaves `disc_count` blank hit the exact same crash;
 * see MediaItemService::withDiscCountDefault()'s own docblock for the fix
 * shared across all of them.
 */
class DiscCountDefaultTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): User
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function library(int $ownerId, string $mediaType): Library
    {
        return Library::query()->create(['name' => 'Novels', 'media_type' => $mediaType, 'owner_id' => $ownerId]);
    }

    public function test_manual_cd_capture_with_no_ean_and_blank_disc_count_succeeds(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id, 'cd');

        $response = $this->postJson("/api/libraries/{$library->id}/items", [
            'title' => 'Happier.', 'artist' => 'As December Falls', 'disc_count' => null,
        ]);

        $response->assertCreated();
        $item = MediaCd::query()->where('title', 'Happier.')->firstOrFail();
        $this->assertSame(1, $item->disc_count);
        $this->assertMatchesRegularExpression('/^NoEAN-\d{13}$/', $item->ean);
    }

    public function test_manual_dvd_bluray_capture_with_blank_disc_count_succeeds(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id, 'dvd_bluray');

        $response = $this->postJson("/api/libraries/{$library->id}/items", [
            'title' => 'A Film', 'ean' => '4000000000001', 'disc_count' => null,
        ]);

        $response->assertCreated();
        $item = MediaDvdBluray::query()->where('title', 'A Film')->firstOrFail();
        $this->assertSame(1, $item->disc_count);
    }

    /** A caller that supplies a real disc_count is unaffected. */
    public function test_manual_cd_capture_with_an_explicit_disc_count_keeps_it(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id, 'cd');

        $response = $this->postJson("/api/libraries/{$library->id}/items", [
            'title' => 'A Box Set', 'ean' => '4000000000002', 'disc_count' => 3,
        ]);

        $response->assertCreated();
        $item = MediaCd::query()->where('title', 'A Box Set')->firstOrFail();
        $this->assertSame(3, $item->disc_count);
    }

    /** GitHub issue #56's metadata-refresh path funnels through the same MediaItemService::updateFromMetadata() — a re-confirmed candidate without a disc count must not crash an existing item's update either. */
    public function test_metadata_refresh_with_a_null_disc_count_does_not_crash(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id, 'cd');
        $item = MediaCd::query()->create([
            'library_id' => $library->id, 'title' => 'Old Title', 'ean' => '4000000000003', 'disc_count' => 2,
        ]);

        $response = $this->postJson("/api/libraries/{$library->id}/items/{$item->id}/metadata/refresh", [
            'attributes' => ['ean' => '4000000000003', 'title' => 'Refreshed Title', 'disc_count' => null],
        ]);

        $response->assertOk();
        $this->assertSame(1, $item->fresh()->disc_count);
    }

    /** A manual edit that clears a previously-set disc_count (MediaItemDetailDialog leaving the field blank) hits the same NOT NULL column — must reset to the default, not crash. */
    public function test_editing_an_item_to_clear_disc_count_falls_back_to_the_default_instead_of_crashing(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id, 'cd');
        $item = MediaCd::query()->create([
            'library_id' => $library->id, 'title' => 'A Box Set', 'ean' => '4000000000004', 'disc_count' => 3,
        ]);

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['disc_count' => null]);

        $response->assertOk();
        $this->assertSame(1, $item->fresh()->disc_count);
    }

    /** GitHub issue #63's bulk-update, setting `disc_count` to blank across a selection, hits the same gap. */
    public function test_bulk_update_clearing_disc_count_falls_back_to_the_default_instead_of_crashing(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id, 'cd');
        $a = MediaCd::query()->create(['library_id' => $library->id, 'title' => 'A', 'ean' => '4000000000005', 'disc_count' => 2]);
        $b = MediaCd::query()->create(['library_id' => $library->id, 'title' => 'B', 'ean' => '4000000000006', 'disc_count' => 4]);

        $response = $this->postJson("/api/libraries/{$library->id}/items/bulk-update", [
            'ids' => [$a->id, $b->id], 'field' => 'disc_count', 'value' => null,
        ]);

        $response->assertOk();
        $this->assertSame(1, $a->fresh()->disc_count);
        $this->assertSame(1, $b->fresh()->disc_count);
    }
}
