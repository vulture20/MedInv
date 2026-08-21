<?php

namespace Tests\Feature;

use App\Domain\Metadata\CurlImageFetcher;
use App\Models\Library;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GitHub issue #166, reported by the user: a candidate chosen from
 * CapturePage.tsx's free-text "ohne EAN erfassen" search (#151) visibly
 * shows a cover in the results list, but it never made it onto the
 * created item — CreateMediaItemDialog only ever had a local-file-upload
 * path, and MediaItemController::store() itself had no `cover_url`
 * concept at all, unlike MetadataController::import()/reimport() (see
 * MetadataImportCoverTest.php's own docblock for why cover-download tests
 * mock CurlImageFetcher rather than Http::fake()). This mirrors that
 * file's own test shape for the same behavior on this endpoint.
 */
class MediaItemStoreCoverUrlTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOwner(): User
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        return $user;
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

    public function test_store_downloads_and_attaches_the_given_cover(): void
    {
        Storage::fake('local');
        $this->mock(CurlImageFetcher::class, fn ($mock) => $mock->shouldReceive('fetch')->andReturn($this->fakeJpegBytes()));
        $owner = $this->actingAsOwner();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->postJson("/api/libraries/{$library->id}/items", [
            'title' => 'Dune',
            'cover_url' => 'https://covers.example.com/dune.jpg',
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('cover_path'));
        Storage::disk('local')->assertExists($response->json('cover_path'));
    }

    public function test_store_succeeds_even_when_the_cover_download_fails(): void
    {
        Storage::fake('local');
        $this->mock(CurlImageFetcher::class, fn ($mock) => $mock->shouldReceive('fetch')->andReturn(null));
        $owner = $this->actingAsOwner();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->postJson("/api/libraries/{$library->id}/items", [
            'title' => 'Dune',
            'cover_url' => 'https://covers.example.com/dune.jpg',
        ]);

        $response->assertCreated();
        $this->assertNull($response->json('cover_path'));
        $this->assertDatabaseHas((new MediaBook)->getTable(), ['title' => 'Dune']);
    }

    public function test_store_without_a_cover_url_does_not_attempt_a_download(): void
    {
        Http::fake();
        $owner = $this->actingAsOwner();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->postJson("/api/libraries/{$library->id}/items", ['title' => 'Dune']);

        $response->assertCreated();
        Http::assertNothingSent();
    }

    /** cover_url is request-only metadata, not a stored column — must never leak into the created record's own attributes. */
    public function test_cover_url_itself_is_not_persisted_as_an_attribute(): void
    {
        Storage::fake('local');
        $this->mock(CurlImageFetcher::class, fn ($mock) => $mock->shouldReceive('fetch')->andReturn($this->fakeJpegBytes()));
        $owner = $this->actingAsOwner();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $this->postJson("/api/libraries/{$library->id}/items", [
            'title' => 'Dune',
            'cover_url' => 'https://covers.example.com/dune.jpg',
        ]);

        $item = MediaBook::query()->where('title', 'Dune')->firstOrFail();
        $this->assertNotSame('https://covers.example.com/dune.jpg', $item->cover_path);
    }
}
