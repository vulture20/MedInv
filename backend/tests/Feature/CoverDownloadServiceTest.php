<?php

namespace Tests\Feature;

use App\Domain\Metadata\CoverDownloadService;
use App\Domain\Metadata\CurlImageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GitHub issue #6: chosen candidate covers (briefing 8.3 step 5) used to be
 * discarded entirely — CoverDownloadService actually fetches and stores
 * them now, independent of the source provider staying up. Extended for
 * the media item detail dialog's manual cover upload/delete + the
 * generated thumbnail every cover gets alongside it (used by
 * MediaItemController::coverThumbnail() for the library item-list view).
 *
 * download() tests mock CurlImageFetcher directly rather than using
 * Http::fake() — CoverDownloadService fetches raw bytes via a real
 * curl_exec() call (CurlImageFetcher), not Laravel's Guzzle-based Http
 * client, specifically because Guzzle gets blocked by some image CDNs
 * (see CurlImageFetcher's docblock) where raw curl doesn't; Http::fake()
 * cannot intercept that call at all.
 */
class CoverDownloadServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeJpegBytes(int $width = 2, int $height = 2): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function fakeTransparentPngBytes(int $width = 40, int $height = 40): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /** Mocks CurlImageFetcher::fetch() to return $bytes (or null, simulating a failed fetch) for any URL. */
    private function fakeFetch(?string $bytes): void
    {
        $this->mock(CurlImageFetcher::class, fn ($mock) => $mock->shouldReceive('fetch')->andReturn($bytes));
    }

    public function test_downloads_and_stores_a_valid_image(): void
    {
        Storage::fake('local');
        $this->fakeFetch($this->fakeJpegBytes());

        $path = app(CoverDownloadService::class)->download('https://covers.example.com/dune.jpg', 'book', '9780000000001');

        $this->assertNotNull($path);
        $this->assertStringStartsWith('covers/book/', $path);
        // image_type_to_extension(IMAGETYPE_JPEG) returns "jpeg", not "jpg".
        $this->assertStringEndsWith('.jpeg', $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_downloading_also_generates_a_thumbnail(): void
    {
        Storage::fake('local');
        $this->fakeFetch($this->fakeJpegBytes(400, 300));
        $service = app(CoverDownloadService::class);

        $path = $service->download('https://covers.example.com/dune.jpg', 'book', '9780000000001');

        Storage::disk('local')->assertExists($service->thumbnailPath($path));
    }

    public function test_thumbnail_is_scaled_down_but_never_upscaled(): void
    {
        Storage::fake('local');

        // A large source: the thumbnail must be smaller than the original in both dimensions.
        $this->fakeFetch($this->fakeJpegBytes(800, 600));
        $largePath = app(CoverDownloadService::class)->download('https://covers.example.com/large.jpg', 'book', '9780000000001');
        [$origWidth, $origHeight] = getimagesize(Storage::disk('local')->path($largePath));
        [$thumbWidth, $thumbHeight] = getimagesize(Storage::disk('local')->path(app(CoverDownloadService::class)->thumbnailPath($largePath)));
        $this->assertLessThan($origWidth, $thumbWidth);
        $this->assertLessThan($origHeight, $thumbHeight);

        // A source already smaller than the thumbnail cap: must stay exactly the original size, not be upscaled.
        $this->fakeFetch($this->fakeJpegBytes(2, 2));
        $tinyPath = app(CoverDownloadService::class)->download('https://covers.example.com/tiny.jpg', 'book', '9780000000002');
        [$tinyThumbWidth, $tinyThumbHeight] = getimagesize(Storage::disk('local')->path(app(CoverDownloadService::class)->thumbnailPath($tinyPath)));
        $this->assertSame(2, $tinyThumbWidth);
        $this->assertSame(2, $tinyThumbHeight);
    }

    public function test_thumbnail_preserves_png_transparency(): void
    {
        Storage::fake('local');
        $this->fakeFetch($this->fakeTransparentPngBytes());
        $service = app(CoverDownloadService::class);

        $path = $service->download('https://covers.example.com/logo.png', 'book', '9780000000001');

        $thumb = imagecreatefromstring(Storage::disk('local')->get($service->thumbnailPath($path)));
        $alpha = (imagecolorat($thumb, 0, 0) >> 24) & 0x7F;
        // GD alpha: 0 = fully opaque, 127 = fully transparent.
        $this->assertSame(127, $alpha);
    }

    public function test_rejects_non_image_content(): void
    {
        Storage::fake('local');
        $this->fakeFetch('<html>not an image</html>');

        $path = app(CoverDownloadService::class)->download('https://covers.example.com/dune.jpg', 'book', '9780000000001');

        $this->assertNull($path);
        Storage::disk('local')->assertDirectoryEmpty('covers');
    }

    public function test_rejects_a_failed_response(): void
    {
        Storage::fake('local');
        // CurlImageFetcher itself already turns a non-2xx status into null — see its own unit-level coverage.
        $this->fakeFetch(null);

        $path = app(CoverDownloadService::class)->download('https://covers.example.com/dune.jpg', 'book', '9780000000001');

        $this->assertNull($path);
    }

    public function test_rejects_oversized_content(): void
    {
        Storage::fake('local');
        $this->fakeFetch(str_repeat('a', 6 * 1024 * 1024));

        $path = app(CoverDownloadService::class)->download('https://covers.example.com/dune.jpg', 'book', '9780000000001');

        $this->assertNull($path);
    }

    public function test_rejects_a_non_http_url_without_attempting_a_request(): void
    {
        Storage::fake('local');
        $this->mock(CurlImageFetcher::class, fn ($mock) => $mock->shouldNotReceive('fetch'));

        $path = app(CoverDownloadService::class)->download('file:///etc/passwd', 'book', '9780000000001');

        $this->assertNull($path);
    }

    /**
     * SSRF guard: `cover_url` reaches download() straight from a user-facing
     * request (MetadataController::import()/reimport()) with nothing
     * upstream restricting it to a public host. A literal loopback/link-
     * local/RFC1918 IP — e.g. a cloud metadata endpoint — must be rejected
     * before this server ever makes the request, not merely have its
     * response discarded afterward.
     */
    public function test_rejects_a_url_targeting_a_private_or_reserved_ip_without_attempting_a_request(): void
    {
        Storage::fake('local');
        $this->mock(CurlImageFetcher::class, fn ($mock) => $mock->shouldNotReceive('fetch'));

        foreach (['http://169.254.169.254/latest/meta-data/', 'http://127.0.0.1/x.jpg', 'http://[::1]/x.jpg', 'http://10.0.0.5/x.jpg'] as $url) {
            $path = app(CoverDownloadService::class)->download($url, 'book', '9780000000001');
            $this->assertNull($path, "expected {$url} to be rejected");
        }
    }

    /** A hostname (as opposed to a literal IP) is never resolved by the guard itself — see download()'s comment for why — so an ordinary external image host still reaches the (here, mocked) fetcher. */
    public function test_a_url_with_an_ordinary_hostname_is_not_blocked_by_the_ssrf_guard(): void
    {
        Storage::fake('local');
        $this->fakeFetch($this->fakeJpegBytes());

        $path = app(CoverDownloadService::class)->download('https://covers.example.com/dune.jpg', 'book', '9780000000001');

        $this->assertNotNull($path);
    }

    /** A transport-level failure (DNS, timeout, connection refused, ...) is exactly what CurlImageFetcher::fetch() itself turns into null — see this test's use of that same contract. */
    public function test_a_connection_failure_is_handled_gracefully(): void
    {
        Storage::fake('local');
        $this->fakeFetch(null);

        $path = app(CoverDownloadService::class)->download('https://covers.example.com/dune.jpg', 'book', '9780000000001');

        $this->assertNull($path);
    }

    public function test_upload_from_file_stores_the_image_and_a_thumbnail(): void
    {
        Storage::fake('local');
        $service = app(CoverDownloadService::class);
        $file = UploadedFile::fake()->image('cover.jpg', 400, 300);

        $path = $service->uploadFromFile($file, 'book', '9780000000001');

        $this->assertNotNull($path);
        $this->assertStringStartsWith('covers/book/', $path);
        Storage::disk('local')->assertExists($path);
        Storage::disk('local')->assertExists($service->thumbnailPath($path));
    }

    public function test_upload_rejects_non_image_content(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('not-a-cover.txt', 'just some text, not an image');

        $path = app(CoverDownloadService::class)->uploadFromFile($file, 'book', '9780000000001');

        $this->assertNull($path);
        Storage::disk('local')->assertDirectoryEmpty('covers');
    }

    public function test_delete_removes_both_the_original_and_the_thumbnail(): void
    {
        Storage::fake('local');
        $service = app(CoverDownloadService::class);
        $path = $service->uploadFromFile(UploadedFile::fake()->image('cover.jpg'), 'book', '9780000000001');
        $thumbnailPath = $service->thumbnailPath($path);
        Storage::disk('local')->assertExists($path);
        Storage::disk('local')->assertExists($thumbnailPath);

        $service->delete($path);

        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertMissing($thumbnailPath);
    }

    public function test_delete_tolerates_a_null_path(): void
    {
        Storage::fake('local');

        app(CoverDownloadService::class)->delete(null);

        $this->expectNotToPerformAssertions();
    }

    /** Defense in depth — see isManagedPath()'s docblock: delete() must never touch a path outside `covers/`, however it ended up stored as a `cover_path`. */
    public function test_delete_refuses_a_path_outside_the_covers_directory(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('backups/medinv-backup-20260101-000000.zip', 'not-actually-a-cover');

        app(CoverDownloadService::class)->delete('backups/medinv-backup-20260101-000000.zip');

        Storage::disk('local')->assertExists('backups/medinv-backup-20260101-000000.zip');
    }

    public function test_thumbnail_path_is_derived_from_the_cover_path(): void
    {
        $service = app(CoverDownloadService::class);

        $this->assertSame(
            'covers/book/thumb_9780000000001-AbCdEfGh.jpeg',
            $service->thumbnailPath('covers/book/9780000000001-AbCdEfGh.jpeg')
        );
    }
}
