<?php

namespace Tests\Feature;

use App\Domain\Metadata\CurlImageFetcher;
use App\Models\Library;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\MetadataPlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GitHub issue #56: re-running the metadata lookup for an already captured
 * item, offering the same per-field picking the initial capture flow uses
 * (MetadataMergeReview.tsx) rather than a blind overwrite. GET .../refresh
 * mirrors BulkImportService::resolveOne()'s {status, merged} shape (already
 * covered end-to-end by BulkImportServiceTest); this file focuses on what's
 * new here — updating an *existing* record instead of creating one, EAN
 * being immutable through this path, and access control.
 */
class MetadataRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_refresh_returns_no_match_when_no_enabled_provider_reports_anything(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);

        $response = $this->getJson("/api/libraries/{$library->id}/items/{$item->id}/metadata/refresh");

        $response->assertOk()->assertJson(['status' => 'no_match']);
    }

    public function test_refresh_looks_up_by_the_items_own_stored_ean(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        MetadataPlugin::query()->create(['provider_key' => 'book.open_library', 'name' => 'Open Library', 'media_type' => 'book', 'enabled' => true]);

        Http::fake([
            'https://openlibrary.org/api/books*' => Http::response([
                'ISBN:9780000000001' => ['title' => 'Dune (revised)', 'authors' => [['name' => 'Frank Herbert']]],
            ], 200),
        ]);

        $response = $this->getJson("/api/libraries/{$library->id}/items/{$item->id}/metadata/refresh");

        $response->assertOk()->assertJson(['status' => 'candidates']);
        $this->assertSame('Dune (revised)', $response->json('merged.fields.title.value'));
    }

    public function test_reimport_updates_the_existing_item_instead_of_creating_a_new_one(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'authors' => 'unknown']);

        $response = $this->postJson("/api/libraries/{$library->id}/items/{$item->id}/metadata/refresh", [
            'attributes' => ['ean' => '9780000000001', 'title' => 'Dune (revised)', 'authors' => 'Frank Herbert'],
        ]);

        $response->assertOk();
        $this->assertSame($item->id, $response->json('id'));
        $this->assertSame(1, MediaBook::query()->count());
        $item->refresh();
        $this->assertSame('Dune (revised)', $item->title);
        $this->assertSame('Frank Herbert', $item->authors);
    }

    /**
     * MetadataMergeReview.tsx always includes `ean` in the attributes it
     * assembles (shared with the create-path confirm flow), but an item's
     * EAN cannot legitimately change through a metadata refresh — same
     * restriction MediaItemController::update() already applies to a
     * manual edit.
     */
    public function test_reimport_never_changes_the_items_ean(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);

        $response = $this->postJson("/api/libraries/{$library->id}/items/{$item->id}/metadata/refresh", [
            'attributes' => ['ean' => '0000000000000', 'title' => 'Dune (revised)'],
        ]);

        $response->assertOk();
        $this->assertSame('9780000000001', $item->fresh()->ean);
    }

    /** GitHub issue #48: a CD's runtime is re-derived from whichever `tracks` selection the reimport actually applies, the same way the initial capture derives it. */
    public function test_reimport_rederives_a_cds_runtime_from_the_newly_selected_tracks(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'CDs', 'media_type' => 'cd', 'owner_id' => $owner->id]);
        $item = MediaCd::query()->create([
            'library_id' => $library->id,
            'title' => 'OK Computer',
            'ean' => '724385522925',
            'tracks' => [['position' => 1, 'title' => 'Old Track', 'duration_seconds' => 100]],
            'runtime_seconds' => 100,
            'runtime_computed' => true,
        ]);

        $response = $this->postJson("/api/libraries/{$library->id}/items/{$item->id}/metadata/refresh", [
            'attributes' => [
                'ean' => '724385522925',
                'title' => 'OK Computer',
                'tracks' => [
                    ['position' => 1, 'title' => 'Airbag', 'duration_seconds' => 284],
                    ['position' => 2, 'title' => 'Paranoid Android', 'duration_seconds' => 383],
                ],
            ],
        ]);

        $response->assertOk();
        $item->refresh();
        $this->assertSame(284 + 383, $item->runtime_seconds);
        $this->assertTrue($item->runtime_computed);
    }

    public function test_refresh_requires_write_access(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $this->actingAsUser(); // a different, unrelated user

        $response = $this->getJson("/api/libraries/{$library->id}/items/{$item->id}/metadata/refresh");

        $response->assertForbidden();
    }

    public function test_reimport_requires_write_access(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $this->actingAsUser(); // a different, unrelated user

        $response = $this->postJson("/api/libraries/{$library->id}/items/{$item->id}/metadata/refresh", [
            'attributes' => ['ean' => '9780000000001', 'title' => 'Hijacked'],
        ]);

        $response->assertForbidden();
        $this->assertSame('Dune', $item->fresh()->title);
    }

    public function test_reimport_downloads_and_replaces_the_cover(): void
    {
        Storage::fake('local');
        $this->mock(CurlImageFetcher::class, fn ($mock) => $mock->shouldReceive('fetch')->andReturn($this->fakeJpegBytes()));
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);

        $response = $this->postJson("/api/libraries/{$library->id}/items/{$item->id}/metadata/refresh", [
            'attributes' => ['ean' => '9780000000001', 'title' => 'Dune'],
            'cover_url' => 'https://covers.example.com/dune.jpg',
        ]);

        $response->assertOk();
        $this->assertNotNull($item->fresh()->cover_path);
        Storage::disk('local')->assertExists($item->fresh()->cover_path);
    }

    private function fakeJpegBytes(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
