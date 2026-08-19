<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #74's "Erfassungsart/Metadaten-Herkunft je Item" idea —
 * MediaBook/MediaCd/MediaDvdBluray::capture_method/metadata_provider/
 * captured_by_user_id (see the migration that added them). One media type
 * (book) stands in for all three, since MediaItemController::store()/
 * MetadataController::import()/reimport() are entirely media-type-generic
 * about these three columns.
 */
class CaptureAttributionTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): User
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function library(int $ownerId): Library
    {
        return Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $ownerId]);
    }

    public function test_manual_entry_via_store_is_recorded_as_manual_with_no_provider(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);

        $response = $this->postJson("/api/libraries/{$library->id}/items", ['title' => 'Dune', 'ean' => '9780000000001']);

        $response->assertCreated();
        $item = MediaBook::query()->where('ean', '9780000000001')->firstOrFail();
        $this->assertSame('manual', $item->capture_method);
        $this->assertNull($item->metadata_provider);
        $this->assertSame($owner->id, $item->captured_by_user_id);
    }

    /** A caller can't smuggle a fake capture_method/metadata_provider/captured_by_user_id into store() via the ordinary create payload — these are server-computed, not client-supplied, from the moment the field was introduced. */
    public function test_store_ignores_an_attacker_supplied_capture_method(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);

        $response = $this->postJson("/api/libraries/{$library->id}/items", [
            'title' => 'Dune', 'ean' => '9780000000001',
            'capture_method' => 'scan', 'metadata_provider' => 'fake_provider', 'captured_by_user_id' => 9999,
        ]);

        $response->assertCreated();
        $item = MediaBook::query()->where('ean', '9780000000001')->firstOrFail();
        $this->assertSame('manual', $item->capture_method);
        $this->assertSame($owner->id, $item->captured_by_user_id);
    }

    public function test_confirmed_metadata_import_is_recorded_as_scan_with_the_joined_provider_keys(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);

        $response = $this->postJson("/api/libraries/{$library->id}/metadata/import", [
            'attributes' => ['ean' => '9780000000002', 'title' => 'Dune'],
            'metadata_providers' => ['open_library', 'google_books', 'open_library'],
        ]);

        $response->assertCreated();
        $item = MediaBook::query()->where('ean', '9780000000002')->firstOrFail();
        $this->assertSame('scan', $item->capture_method);
        $this->assertSame('open_library,google_books', $item->metadata_provider);
        $this->assertSame($owner->id, $item->captured_by_user_id);
    }

    /** attributes.capture_method/metadata_provider/captured_by_user_id must be stripped the same way attributes.cover_path/library_id already are (MetadataImportMassAssignmentTest). */
    public function test_import_ignores_attacker_supplied_capture_fields_inside_attributes(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);

        $response = $this->postJson("/api/libraries/{$library->id}/metadata/import", [
            'attributes' => [
                'ean' => '9780000000003', 'title' => 'Dune',
                'capture_method' => 'manual', 'metadata_provider' => 'fake', 'captured_by_user_id' => 9999,
            ],
        ]);

        $response->assertCreated();
        $item = MediaBook::query()->where('ean', '9780000000003')->firstOrFail();
        $this->assertSame('scan', $item->capture_method);
        $this->assertNull($item->metadata_provider);
        $this->assertSame($owner->id, $item->captured_by_user_id);
    }

    public function test_reimport_updates_metadata_provider_but_leaves_capture_method_untouched(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);
        $item = MediaBook::query()->create([
            'library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000004',
            'capture_method' => 'manual', 'captured_by_user_id' => $owner->id,
        ]);

        $response = $this->postJson("/api/libraries/{$library->id}/items/{$item->id}/metadata/refresh", [
            'attributes' => ['description' => 'A desert planet epic.'],
            'metadata_providers' => ['open_library'],
        ]);

        $response->assertOk();
        $fresh = $item->fresh();
        $this->assertSame('manual', $fresh->capture_method);
        $this->assertSame('open_library', $fresh->metadata_provider);
    }
}
