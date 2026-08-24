<?php

namespace Tests\Feature;

use App\Domain\Metadata\CurlImageFetcher;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * CurlImageFetcher::fetch() (GitHub issue #46 — see OutgoingHttpLoggingTest's
 * docblock for the full picture) deliberately bypasses the `Http` facade
 * (its own docblock explains why: a real Cloudflare block Guzzle hit but raw
 * curl didn't), so Http::fake() cannot intercept it at all — there is no
 * mockable seam to exercise fetch()'s *own* logic against without a real
 * HTTP round trip. This spins up PHP's built-in web server as a genuine
 * local fixture (a router script serving a real image / a 404 / nothing at
 * all) rather than trying to fake curl_exec() itself, which isn't feasible
 * without a PECL extension this project doesn't depend on.
 */
class CurlImageFetcherTest extends TestCase
{
    private static int $port;

    private static string $fixtureDir;

    /** @var resource|null */
    private static $serverProcess;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$port = self::findFreePort();
        self::$fixtureDir = sys_get_temp_dir().'/curl-image-fetcher-test-'.getmypid();
        mkdir(self::$fixtureDir);

        // A real, valid JPEG — not just arbitrary bytes — so this exercises
        // the exact same "real image file" shape a metadata provider's
        // cover URL actually returns.
        $image = imagecreatetruecolor(4, 4);
        imagejpeg($image, self::$fixtureDir.'/image.jpg');
        imagedestroy($image);

        file_put_contents(self::$fixtureDir.'/router.php', <<<'PHP'
            <?php
            if ($_SERVER['REQUEST_URI'] === '/image.jpg') {
                header('Content-Type: image/jpeg');
                readfile(__DIR__.'/image.jpg');
                return true;
            }
            http_response_code(404);
            echo 'not found';
            PHP);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        self::$serverProcess = proc_open(
            ['php', '-S', '127.0.0.1:'.self::$port, 'router.php'],
            $descriptors,
            $pipes,
            self::$fixtureDir,
        );

        // Wait for the built-in server to actually accept connections before
        // any test tries to use it — proc_open() returning doesn't mean the
        // server inside is listening yet.
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if (@fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1)) {
                break;
            }
            usleep(50_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        array_map('unlink', glob(self::$fixtureDir.'/*'));
        rmdir(self::$fixtureDir);

        parent::tearDownAfterClass();
    }

    private static function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $port = (int) explode(':', stream_socket_get_name($socket, false))[1];
        fclose($socket);

        return $port;
    }

    private function baseUrl(): string
    {
        return 'http://127.0.0.1:'.self::$port;
    }

    public function test_a_successful_fetch_is_logged_with_status_duration_content_type_and_size(): void
    {
        Log::shouldReceive('debug')->once()->with('Outgoing HTTP request/response (cover download)', Mockery::on(function (array $context) {
            return $context['method'] === 'GET'
                && str_ends_with($context['url'], '/image.jpg')
                && $context['status'] === 200
                && array_key_exists('duration_ms', $context)
                && $context['content_type'] === 'image/jpeg'
                && $context['bytes'] > 0;
        }));
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $body = (new CurlImageFetcher)->fetch($this->baseUrl().'/image.jpg');

        $this->assertNotNull($body);
        $this->assertGreaterThan(0, strlen($body));
    }

    public function test_a_404_response_is_logged_with_its_status_and_returns_null(): void
    {
        Log::shouldReceive('debug')->once()->with('Outgoing HTTP request/response (cover download)', Mockery::on(function (array $context) {
            // Content-Type is whatever the 404 response actually declared
            // (the built-in server's own default, "text/html..." here) —
            // the point under test is the status code, not this field.
            return $context['status'] === 404;
        }));
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $body = (new CurlImageFetcher)->fetch($this->baseUrl().'/does-not-exist');

        $this->assertNull($body);
    }

    /**
     * GitHub issue #83: proves $resolveToIps actually takes effect, not
     * just that passing it doesn't error. `pin-test.invalid` uses the
     * `.invalid` TLD (RFC 2606 — reserved to never resolve, by design) so
     * this can only possibly succeed via CURLOPT_RESOLVE pinning it to the
     * local fixture server; a real, unmocked DNS lookup for this hostname
     * is guaranteed to fail everywhere, making this deterministic without
     * needing to fake curl or DNS itself.
     */
    public function test_pins_the_connection_to_the_given_resolved_ips_instead_of_letting_curl_resolve_the_hostname(): void
    {
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $body = (new CurlImageFetcher)->fetch('http://pin-test.invalid:'.self::$port.'/image.jpg', ['127.0.0.1']);

        $this->assertNotNull($body);
        $this->assertGreaterThan(0, strlen($body));
    }

    /** Without a pin, the same never-resolvable `.invalid` hostname correctly fails at the transport level — confirms the test above's success is really down to CURLOPT_RESOLVE, not some other fallback. */
    public function test_an_unpinned_never_resolvable_hostname_fails(): void
    {
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $body = (new CurlImageFetcher)->fetch('http://pin-test.invalid:'.self::$port.'/image.jpg');

        $this->assertNull($body);
    }

    /** A transport-level failure (e.g. connection refused) has no status/content-type/size at all — logged as null, not omitted or a crash — and still fires the existing 'Cover download failed.' INFO line unchanged. */
    public function test_a_connection_failure_is_logged_with_null_status_and_still_logs_the_existing_failure_line(): void
    {
        $unreachablePort = self::findFreePort();

        Log::shouldReceive('debug')->once()->with('Outgoing HTTP request/response (cover download)', Mockery::on(function (array $context) use ($unreachablePort) {
            return $context['status'] === null
                && $context['content_type'] === null
                && $context['bytes'] === null
                && str_contains($context['url'], (string) $unreachablePort);
        }));
        Log::shouldReceive('info')->once()->with('Cover download failed.', Mockery::on(fn (array $context) => array_key_exists('error', $context)));

        $body = (new CurlImageFetcher)->fetch("http://127.0.0.1:{$unreachablePort}/image.jpg");

        $this->assertNull($body);
    }
}
