<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * GitHub issue #203: a user reported the generic frontend error message
 * while editing a media item, with no trace of the real cause in
 * storage/logs/laravel.log. Investigation found MediaItemController never
 * called Controller::logApiError() anywhere — unlike almost every other
 * controller in this app — so a denied write (403, HttpException) or a
 * missing item (404, ModelNotFoundException) left zero log entry at all
 * (both sit on Laravel's own $internalDontReport list), and a caught
 * DuplicateEanException (409) was silently converted to a response without
 * ever being logged either. This class covers the new logging
 * MediaItemController::abortUnlessCanWrite()/findMediaItemOrAbort() and the
 * additional logApiError() calls at the existing DuplicateEanException/
 * move()-domain-error response sites now add — one representative endpoint
 * per log type, since the same helper methods back all eight write
 * endpoints (store, update, destroy, move, uploadCover, deleteCover,
 * bulkDestroy, bulkUpdate).
 *
 * Log::shouldReceive('debug')->zeroOrMoreTimes() plus a specific expectation
 * per test, and the Log::error()-capturing setUp()/tearDown() below, mirror
 * AuthLoggingTest.php's own established pattern — see that class's docblock
 * for why (GitHub issue #42: an unmocked Log:: call once any expectation
 * exists throws a confusing Mockery error rather than a clear test failure).
 */
class MediaItemLoggingTest extends TestCase
{
    use RefreshDatabase;

    private ?\Throwable $unexpectedLoggedError = null;

    protected function setUp(): void
    {
        parent::setUp();

        Log::shouldReceive('error')->zeroOrMoreTimes()->andReturnUsing(function (string $message, array $context = []) {
            $this->unexpectedLoggedError = $context['exception'] ?? new \RuntimeException($message);
        });
    }

    protected function tearDown(): void
    {
        $captured = $this->unexpectedLoggedError;
        $this->unexpectedLoggedError = null;

        try {
            parent::tearDown();
        } finally {
            if ($captured) {
                self::fail(sprintf(
                    "Log::error() was called during the request — %s: %s in %s:%d\n%s",
                    $captured::class,
                    $captured->getMessage(),
                    $captured->getFile(),
                    $captured->getLine(),
                    $captured->getTraceAsString(),
                ));
            }
        }
    }

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_denied_write_access_is_logged_at_debug_level(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $this->actingAsUser(); // a different, unrelated user — library is not shared with them

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('log')->once()->with('debug', 'Write access denied for a media item request.', Mockery::on(function ($context) use ($library, $item) {
            return $context['error_code'] === 'write_access_denied'
                && $context['library_id'] === $library->id
                && $context['item_id'] === $item->id
                && array_key_exists('ip', $context);
        }));

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['title' => 'Hijacked']);

        $response->assertForbidden();
    }

    public function test_a_missing_media_item_is_logged_at_debug_level(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('log')->once()->with('debug', "Media item 999999 not found in library {$library->id}.", Mockery::on(function ($context) use ($library) {
            return $context['error_code'] === 'media_item_not_found'
                && $context['library_id'] === $library->id
                && $context['item_id'] === 999999;
        }));

        $response = $this->deleteJson("/api/libraries/{$library->id}/items/999999");

        $response->assertNotFound();
    }

    public function test_a_duplicate_ean_on_create_is_logged(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('log')->once()->with('warning', 'A media item with EAN 9780000000001 already exists in this library.', Mockery::on(function ($context) use ($library) {
            return $context['error_code'] === 'duplicate_ean'
                && $context['library_id'] === $library->id
                && $context['ean'] === '9780000000001';
        }));

        $response = $this->postJson("/api/libraries/{$library->id}/items", ['title' => 'Dune (again)', 'ean' => '9780000000001']);

        $response->assertStatus(409);
    }

    public function test_a_duplicate_ean_on_update_is_logged(): void
    {
        $owner = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Existing', 'ean' => '9780000000002']);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        SystemSetting::set('ean_editing.enabled', true);
        $this->actingAsUser('admin');

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('log')->once()->with('warning', 'A media item with EAN 9780000000002 already exists in this library.', Mockery::on(function ($context) use ($library, $item) {
            return $context['error_code'] === 'duplicate_ean'
                && $context['library_id'] === $library->id
                && $context['item_id'] === $item->id
                && $context['ean'] === '9780000000002';
        }));

        $response = $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['ean' => '9780000000002']);

        $response->assertStatus(409);
    }

    public function test_a_duplicate_ean_on_move_is_logged(): void
    {
        $owner = $this->actingAsUser('admin');
        $source = Library::query()->create(['name' => 'Source', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $target = Library::query()->create(['name' => 'Target', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $target->id, 'title' => 'Elsewhere', 'ean' => '9780000000001']);
        $item = MediaBook::query()->create(['library_id' => $source->id, 'title' => 'Dune', 'ean' => '9780000000001']);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('log')->once()->with('warning', 'A media item with EAN 9780000000001 already exists in this library.', Mockery::on(function ($context) use ($target, $item) {
            return $context['error_code'] === 'duplicate_ean'
                && $context['library_id'] === $target->id
                && $context['item_id'] === $item->id
                && $context['ean'] === '9780000000001';
        }));

        $response = $this->postJson("/api/libraries/{$source->id}/items/{$item->id}/move", ['target_library_id' => $target->id]);

        $response->assertStatus(409);
    }

    public function test_moving_an_item_to_its_own_library_is_logged(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('log')->once()->with('warning', 'Item is already in this library.', Mockery::on(function ($context) {
            return $context['error_code'] === 'same_library';
        }));

        $response = $this->postJson("/api/libraries/{$library->id}/items/{$item->id}/move", ['target_library_id' => $library->id]);

        $response->assertStatus(422);
    }

    public function test_moving_an_item_to_a_different_media_type_library_is_logged(): void
    {
        $owner = $this->actingAsUser();
        $source = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $target = Library::query()->create(['name' => 'Movies', 'media_type' => 'dvd_bluray', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $source->id, 'title' => 'Dune', 'ean' => '9780000000001']);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('log')->once()->with('warning', 'Target library has a different media type.', Mockery::on(function ($context) {
            return $context['error_code'] === 'media_type_mismatch';
        }));

        $response = $this->postJson("/api/libraries/{$source->id}/items/{$item->id}/move", ['target_library_id' => $target->id]);

        $response->assertStatus(422);
    }
}
