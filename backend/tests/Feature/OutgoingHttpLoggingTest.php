<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AppServiceProvider::logOutgoingHttpRequests() — every call the metadata
 * providers (app/Domain/Metadata/Providers/) make via the `Http` facade is
 * now logged at DEBUG, previously invisible in the log entirely. Registered
 * unconditionally in AppServiceProvider::boot(), which already runs once
 * per application bootstrap (including in these tests), so Http::fake() +
 * a plain Http::get() call here exercises the exact same global on_stats
 * hook a real provider request would.
 *
 * The log message is "...request/response..." (GitHub issue #46) — a real
 * report mistook the message *name* alone for evidence the response wasn't
 * logged, when it always was, embedded in this same line's own
 * status/duration_ms/response_body fields (see logOutgoingHttpRequests()'s
 * docblock). The one outgoing-HTTP path this really didn't cover — cover
 * image downloads, which bypass `Http` entirely — is covered separately by
 * CurlImageFetcherTest.
 */
class OutgoingHttpLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_request_is_logged_with_method_status_duration_and_body(): void
    {
        Http::fake(['https://example.com/api/lookup*' => Http::response(['title' => 'Dune'], 200)]);

        Log::shouldReceive('debug')->once()->with('Outgoing HTTP request/response (metadata provider)', Mockery::on(function (array $context) {
            return $context['method'] === 'GET'
                && $context['url'] === 'https://example.com/api/lookup'
                && $context['status'] === 200
                && array_key_exists('duration_ms', $context)
                && str_contains($context['response_body'], 'Dune');
        }));

        Http::get('https://example.com/api/lookup');
    }

    public function test_a_failed_response_is_still_logged_with_its_status(): void
    {
        Http::fake(['https://example.com/api/lookup*' => Http::response(['error' => 'not found'], 404)]);

        Log::shouldReceive('debug')->once()->with('Outgoing HTTP request/response (metadata provider)', Mockery::on(function (array $context) {
            return $context['status'] === 404;
        }));

        Http::get('https://example.com/api/lookup');
    }

    /** GoogleBooksProvider's real auth convention — see REDACTED_QUERY_PARAMS' docblock. */
    public function test_a_key_query_parameter_is_redacted(): void
    {
        Http::fake(['https://example.com/*' => Http::response([], 200)]);

        Log::shouldReceive('debug')->once()->with('Outgoing HTTP request/response (metadata provider)', Mockery::on(function (array $context) {
            return $context['url'] === 'https://example.com/api/lookup?key=%5BREDACTED%5D&q=dune';
        }));

        Http::get('https://example.com/api/lookup', ['key' => 'TOTALLY-SECRET-KEY', 'q' => 'dune']);
    }

    #[DataProvider('sensitiveParamNames')]
    public function test_other_common_secret_param_names_are_also_redacted(string $paramName): void
    {
        Http::fake(['https://example.com/*' => Http::response([], 200)]);

        Log::shouldReceive('debug')->once()->with('Outgoing HTTP request/response (metadata provider)', Mockery::on(function (array $context) use ($paramName) {
            return ! str_contains($context['url'], 'TOTALLY-SECRET-VALUE')
                && str_contains($context['url'], "{$paramName}=%5BREDACTED%5D");
        }));

        Http::get('https://example.com/api/lookup', [$paramName => 'TOTALLY-SECRET-VALUE']);
    }

    public static function sensitiveParamNames(): array
    {
        return [['api_key'], ['apikey'], ['token'], ['access_token'], ['secret']];
    }

    public function test_a_url_without_any_query_string_is_logged_unchanged(): void
    {
        Http::fake(['https://example.com/api/lookup' => Http::response([], 200)]);

        Log::shouldReceive('debug')->once()->with('Outgoing HTTP request/response (metadata provider)', Mockery::on(function (array $context) {
            return $context['url'] === 'https://example.com/api/lookup';
        }));

        Http::get('https://example.com/api/lookup');
    }

    public function test_a_non_sensitive_query_parameter_is_left_untouched(): void
    {
        Http::fake(['https://example.com/*' => Http::response([], 200)]);

        Log::shouldReceive('debug')->once()->with('Outgoing HTTP request/response (metadata provider)', Mockery::on(function (array $context) {
            return $context['url'] === 'https://example.com/api/lookup?q=dune';
        }));

        Http::get('https://example.com/api/lookup', ['q' => 'dune']);
    }

    /** A long response body is truncated rather than logged in full — see logOutgoingHttpRequests()'s docblock. */
    public function test_a_large_response_body_is_truncated(): void
    {
        $largeBody = str_repeat('x', 5000);
        Http::fake(['https://example.com/*' => Http::response($largeBody, 200)]);

        Log::shouldReceive('debug')->once()->with('Outgoing HTTP request/response (metadata provider)', Mockery::on(function (array $context) {
            return strlen($context['response_body']) < 5000;
        }));

        Http::get('https://example.com/api/lookup');
    }

    /**
     * Real end-to-end confirmation (not just that the logger fires): a
     * provider's own ->json() call must still see the full response after
     * logOutgoingHttpRequests() has already read the same underlying
     * stream — see that method's docblock for why this is expected to be
     * safe (PSR-7 stream __toString() rewinds first).
     */
    public function test_reading_the_response_body_for_logging_does_not_break_the_callers_own_read(): void
    {
        Http::fake(['https://example.com/api/lookup' => Http::response(['title' => 'Dune'], 200)]);

        $response = Http::get('https://example.com/api/lookup');

        $this->assertSame(['title' => 'Dune'], $response->json());
    }
}
