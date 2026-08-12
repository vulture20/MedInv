<?php

namespace Tests\Feature;

use App\Domain\Metadata\CoverDownloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GitHub issue #6: chosen candidate covers (briefing 8.3 step 5) used to be
 * discarded entirely — CoverDownloadService actually fetches and stores
 * them now, independent of the source provider staying up.
 */
class CoverDownloadServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeJpegBytes(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    public function test_downloads_and_stores_a_valid_image(): void
    {
        Storage::fake('local');
        Http::fake(['https://covers.example.com/dune.jpg' => Http::response($this->fakeJpegBytes(), 200)]);

        $path = app(CoverDownloadService::class)->download('https://covers.example.com/dune.jpg', 'book', '9780000000001');

        $this->assertNotNull($path);
        $this->assertStringStartsWith('covers/book/', $path);
        // image_type_to_extension(IMAGETYPE_JPEG) returns "jpeg", not "jpg".
        $this->assertStringEndsWith('.jpeg', $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_rejects_non_image_content(): void
    {
        Storage::fake('local');
        Http::fake(['https://covers.example.com/dune.jpg' => Http::response('<html>not an image</html>', 200)]);

        $path = app(CoverDownloadService::class)->download('https://covers.example.com/dune.jpg', 'book', '9780000000001');

        $this->assertNull($path);
        Storage::disk('local')->assertDirectoryEmpty('covers');
    }

    public function test_rejects_a_failed_response(): void
    {
        Storage::fake('local');
        Http::fake(['https://covers.example.com/dune.jpg' => Http::response('Not Found', 404)]);

        $path = app(CoverDownloadService::class)->download('https://covers.example.com/dune.jpg', 'book', '9780000000001');

        $this->assertNull($path);
    }

    public function test_rejects_oversized_content(): void
    {
        Storage::fake('local');
        Http::fake(['https://covers.example.com/dune.jpg' => Http::response(str_repeat('a', 6 * 1024 * 1024), 200)]);

        $path = app(CoverDownloadService::class)->download('https://covers.example.com/dune.jpg', 'book', '9780000000001');

        $this->assertNull($path);
    }

    public function test_rejects_a_non_http_url_without_attempting_a_request(): void
    {
        Storage::fake('local');
        Http::fake();

        $path = app(CoverDownloadService::class)->download('file:///etc/passwd', 'book', '9780000000001');

        $this->assertNull($path);
        Http::assertNothingSent();
    }

    public function test_a_connection_failure_is_handled_gracefully(): void
    {
        Storage::fake('local');
        Http::fake(function (HttpRequest $request) {
            throw new ConnectionException('Could not connect.');
        });

        $path = app(CoverDownloadService::class)->download('https://covers.example.com/dune.jpg', 'book', '9780000000001');

        $this->assertNull($path);
    }
}
