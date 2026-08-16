<?php

namespace Tests\Feature;

use App\Domain\ExportImport\ExportImportService;
use App\Models\Library;
use App\Models\MediaBook;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GitHub issue #64: a newly captured item's price is converted into the
 * configured default currency (`statistics.default_currency`, #62/#58) at
 * entry time via a live Frankfurter.dev exchange rate, so
 * StatisticsService::overviewFor()'s sum('price') stays meaningful without
 * that (or any other) consumer needing to handle multiple currencies
 * itself. See CurrencyConversionService's docblock for the full reasoning,
 * including why this deliberately does not touch MediaItemController::
 * update() or ExportImportService's backup/import restore path.
 */
class CurrencyConversionTest extends TestCase
{
    use RefreshDatabase;

    private const API_URL = 'https://api.frankfurter.dev/v1/latest';

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function fakeRate(string $from, string $to, float $rate): void
    {
        Http::fake([
            self::API_URL.'*' => Http::response([
                'amount' => 1, 'base' => $from, 'date' => '2026-08-16', 'rates' => [$to => $rate],
            ], 200),
        ]);
    }

    public function test_manual_creation_converts_a_mismatched_price_into_the_default_currency(): void
    {
        SystemSetting::set('statistics.default_currency', 'EUR');
        $this->fakeRate('USD', 'EUR', 0.92);
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->postJson("/api/libraries/{$library->id}/items", [
            'title' => 'Dune', 'ean' => '9780000000001', 'price' => 10.00, 'currency' => 'USD',
        ]);

        $response->assertCreated();
        $this->assertSame('EUR', $response->json('currency'));
        $this->assertSame('9.20', $response->json('price'));
        $this->assertDatabaseHas((new MediaBook)->getTable(), ['ean' => '9780000000001', 'currency' => 'EUR', 'price' => 9.2]);
        Http::assertSent(fn ($request) => $request->url() === self::API_URL.'?base=USD&symbols=EUR');
    }

    public function test_a_price_already_in_the_default_currency_is_left_untouched_and_no_request_is_made(): void
    {
        SystemSetting::set('statistics.default_currency', 'EUR');
        Http::fake();
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->postJson("/api/libraries/{$library->id}/items", [
            'title' => 'Dune', 'ean' => '9780000000001', 'price' => 10.00, 'currency' => 'EUR',
        ]);

        $response->assertCreated();
        $this->assertSame('10.00', $response->json('price'));
        Http::assertNothingSent();
    }

    public function test_no_conversion_happens_when_no_default_currency_is_configured(): void
    {
        Http::fake();
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->postJson("/api/libraries/{$library->id}/items", [
            'title' => 'Dune', 'ean' => '9780000000001', 'price' => 10.00, 'currency' => 'USD',
        ]);

        $response->assertCreated();
        $this->assertSame('USD', $response->json('currency'));
        $this->assertSame('10.00', $response->json('price'));
        Http::assertNothingSent();
    }

    public function test_an_item_with_no_price_or_currency_triggers_no_lookup(): void
    {
        SystemSetting::set('statistics.default_currency', 'EUR');
        Http::fake();
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->postJson("/api/libraries/{$library->id}/items", ['title' => 'Dune', 'ean' => '9780000000001']);

        $response->assertCreated();
        Http::assertNothingSent();
    }

    /** GitHub issue #53's precedent, applied here too: an optional enrichment step's failure must not block the actual capture. */
    public function test_a_failed_rate_lookup_leaves_the_original_price_and_currency_in_place(): void
    {
        SystemSetting::set('statistics.default_currency', 'EUR');
        Http::fake([self::API_URL.'*' => Http::response(['message' => 'not found'], 404)]);
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->postJson("/api/libraries/{$library->id}/items", [
            'title' => 'Dune', 'ean' => '9780000000001', 'price' => 10.00, 'currency' => 'USD',
        ]);

        $response->assertCreated();
        $this->assertSame('USD', $response->json('currency'));
        $this->assertSame('10.00', $response->json('price'));
    }

    public function test_metadata_import_confirmation_also_converts_a_mismatched_price(): void
    {
        SystemSetting::set('statistics.default_currency', 'EUR');
        $this->fakeRate('USD', 'EUR', 0.92);
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->postJson("/api/libraries/{$library->id}/metadata/import", [
            'attributes' => ['title' => 'Dune', 'ean' => '9780000000001', 'price' => 10.00, 'currency' => 'USD'],
        ]);

        $response->assertCreated();
        $this->assertSame('EUR', $response->json('currency'));
        $this->assertSame('9.20', $response->json('price'));
    }

    /**
     * Restoring a backup/import must reproduce its stored values exactly —
     * never re-converted against whatever the exchange rate happens to be
     * on restore day. Exercises the real export -> import round trip
     * (rather than a hand-built payload) so this asserts against
     * ExportImportService's actual serialization shape, not a guess at it.
     */
    public function test_importing_an_exported_library_does_not_convert_prices(): void
    {
        SystemSetting::set('statistics.default_currency', 'EUR');
        Http::fake();
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $admin->id]);
        // Created directly (not via the /api/libraries/{id}/items endpoint) so
        // this record is exactly the kind of already-historical USD price a
        // real import/restore would carry — the conversion happening here
        // too (it must not) would defeat the point of this test.
        MediaBook::query()->create([
            'library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'price' => 10.00, 'currency' => 'USD',
        ]);
        $exported = app(ExportImportService::class)->exportLibraries([$library->id]);
        MediaBook::query()->where('ean', '9780000000001')->delete();
        $library->delete();

        app(ExportImportService::class)->importLibraries($exported, $admin);

        $this->assertDatabaseHas((new MediaBook)->getTable(), ['ean' => '9780000000001', 'currency' => 'USD', 'price' => 10.00]);
        Http::assertNothingSent();
    }
}
