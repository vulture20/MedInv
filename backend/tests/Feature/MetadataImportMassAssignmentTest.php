<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security fix: MetadataController::import()/reimport() deliberately pass
 * `attributes` through to MediaItemService::create()/updateFromMetadata()
 * without a per-field Laravel validation rule (see import()'s own comment
 * for why — a Laravel quirk that would otherwise silently drop every other
 * field). That meant `attributes.cover_path` and `attributes.library_id`
 * were mass-assignable straight from an ordinary user's request, even
 * though both are `#[Fillable]` on every media model and neither is
 * legitimate item data:
 *
 * - `cover_path` is meant to be set only by CoverDownloadService, which
 *   always generates a `covers/<media_type>/<random>` path. A caller could
 *   instead point it at *any* path on the `local` disk — e.g. a backup zip
 *   under `backups/...` (see BackupService — full instance backups,
 *   including every user's password hash and every metadata-provider API
 *   key) — which MediaItemController::cover()/coverThumbnail() would then
 *   happily stream back, and deleteCover()/destroy() would delete, to
 *   *any* non-guest user with write access to *any* library of their own
 *   (canWriteItems(), not an owner/admin-only check).
 * - `library_id` could move the created/updated item into a library the
 *   caller has no access to at all, bypassing move()'s own ownership and
 *   media-type checks.
 *
 * `stripInternallyManagedFields()` (private on MetadataController) now
 * removes both before either method reaches MediaItemService. See
 * CoverDownloadServiceTest's SSRF-guard test and MediaItemController's own
 * isManagedPath() defense-in-depth coverage for the two related fixes this
 * pairs with.
 */
class MetadataImportMassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): User
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function library(int $ownerId, string $name = 'Novels'): Library
    {
        return Library::query()->create(['name' => $name, 'media_type' => 'book', 'owner_id' => $ownerId]);
    }

    public function test_import_ignores_an_attacker_supplied_cover_path(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);

        $response = $this->postJson("/api/libraries/{$library->id}/metadata/import", [
            'attributes' => [
                'ean' => '9780000000001',
                'title' => 'Injected',
                'cover_path' => 'backups/medinv-backup-20260101-000000.zip',
            ],
        ]);

        $response->assertCreated();
        $item = MediaBook::query()->where('ean', '9780000000001')->firstOrFail();
        $this->assertNull($item->cover_path);
    }

    public function test_import_ignores_an_attacker_supplied_library_id(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id, 'Mine');
        $otherOwner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $otherLibrary = $this->library($otherOwner->id, 'Someone Elses');

        $response = $this->postJson("/api/libraries/{$library->id}/metadata/import", [
            'attributes' => [
                'ean' => '9780000000002',
                'title' => 'Injected',
                'library_id' => $otherLibrary->id,
            ],
        ]);

        $response->assertCreated();
        $item = MediaBook::query()->where('ean', '9780000000002')->firstOrFail();
        $this->assertSame($library->id, $item->library_id);
    }

    public function test_reimport_ignores_an_attacker_supplied_cover_path(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Original', 'ean' => '9780000000003']);

        $response = $this->postJson("/api/libraries/{$library->id}/items/{$item->id}/metadata/refresh", [
            'attributes' => [
                'ean' => '9780000000003',
                'title' => 'Injected',
                'cover_path' => 'backups/medinv-backup-20260101-000000.zip',
            ],
        ]);

        $response->assertOk();
        $this->assertNull($item->fresh()->cover_path);
    }

    public function test_reimport_ignores_an_attacker_supplied_library_id(): void
    {
        $owner = $this->actingAsUser();
        $library = $this->library($owner->id, 'Mine');
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Original', 'ean' => '9780000000004']);
        $otherOwner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $otherLibrary = $this->library($otherOwner->id, 'Someone Elses');

        $response = $this->postJson("/api/libraries/{$library->id}/items/{$item->id}/metadata/refresh", [
            'attributes' => [
                'ean' => '9780000000004',
                'title' => 'Injected',
                'library_id' => $otherLibrary->id,
            ],
        ]);

        $response->assertOk();
        $this->assertSame($library->id, $item->fresh()->library_id);
    }
}
