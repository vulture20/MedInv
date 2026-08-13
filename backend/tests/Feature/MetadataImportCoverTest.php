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
 * GitHub issue #6: MetadataController::import() actually downloads the
 * chosen cover now. Cover-download tests mock CurlImageFetcher directly
 * rather than Http::fake() — see CoverDownloadServiceTest's docblock for
 * why (CoverDownloadService fetches raw bytes via a real curl_exec() call
 * that Http::fake() cannot intercept).
 */
class MetadataImportCoverTest extends TestCase
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

    public function test_import_downloads_and_attaches_the_chosen_cover(): void
    {
        Storage::fake('local');
        $this->mock(CurlImageFetcher::class, fn ($mock) => $mock->shouldReceive('fetch')->andReturn($this->fakeJpegBytes()));
        $owner = $this->actingAsOwner();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->postJson("/api/libraries/{$library->id}/metadata/import", [
            'attributes' => ['title' => 'Dune', 'ean' => '9780000000001'],
            'cover_url' => 'https://covers.example.com/dune.jpg',
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('cover_path'));
        Storage::disk('local')->assertExists($response->json('cover_path'));
    }

    public function test_import_succeeds_even_when_the_cover_download_fails(): void
    {
        Storage::fake('local');
        $this->mock(CurlImageFetcher::class, fn ($mock) => $mock->shouldReceive('fetch')->andReturn(null));
        $owner = $this->actingAsOwner();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->postJson("/api/libraries/{$library->id}/metadata/import", [
            'attributes' => ['title' => 'Dune', 'ean' => '9780000000001'],
            'cover_url' => 'https://covers.example.com/dune.jpg',
        ]);

        $response->assertCreated();
        $this->assertNull($response->json('cover_path'));
        $this->assertDatabaseHas((new MediaBook)->getTable(), ['title' => 'Dune']);
    }

    /**
     * Regression test: combining a top-level 'attributes' => 'array' rule
     * with a nested 'attributes.ean' => [...] rule made Laravel's
     * validate() silently drop every other key (title, authors, ...) from
     * its output — see MetadataController::import()'s comment. Confirms
     * the whole attribute set survives, not just the field that happens to
     * have been in the mix that triggered the discovery (ean).
     */
    public function test_import_preserves_every_attribute_not_just_ean(): void
    {
        Http::fake();
        $owner = $this->actingAsOwner();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->postJson("/api/libraries/{$library->id}/metadata/import", [
            'attributes' => [
                'title' => 'Dune',
                'ean' => '9780000000001',
                'authors' => 'Frank Herbert',
                'publisher' => 'Ace Books',
            ],
        ]);

        $response->assertCreated();
        $this->assertSame('Dune', $response->json('title'));
        $this->assertSame('Frank Herbert', $response->json('authors'));
        $this->assertSame('Ace Books', $response->json('publisher'));
    }

    public function test_import_without_a_cover_url_does_not_attempt_a_download(): void
    {
        Http::fake();
        $owner = $this->actingAsOwner();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->postJson("/api/libraries/{$library->id}/metadata/import", [
            'attributes' => ['title' => 'Dune', 'ean' => '9780000000001'],
        ]);

        $response->assertCreated();
        Http::assertNothingSent();
    }
}
