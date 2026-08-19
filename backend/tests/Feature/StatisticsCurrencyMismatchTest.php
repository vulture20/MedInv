<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #62's third, scoped-down alternative (explicitly chosen over
 * the other two): an admin-configurable instance-wide default currency
 * (AdminSettingsController::updateStatistics()) plus a `currency_mismatch`
 * flag on GET /statistics's per-library entries whenever a library holds an
 * item whose `currency` (#58) disagrees with it. Deliberately does not fix
 * `total_value` itself — see StatisticsService::overviewFor()'s docblock
 * and GitHub issue #64, filed to track that this alternative was chosen
 * specifically because it's simpler, not because it actually resolves the
 * underlying "mixed currencies summed as one meaningless number" problem.
 */
class StatisticsCurrencyMismatchTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    private function statsFor(int $libraryId): array
    {
        $response = $this->getJson('/api/statistics');
        $response->assertOk();

        return collect($response->json())->firstWhere('library_id', $libraryId);
    }

    public function test_no_mismatch_is_ever_flagged_when_no_default_currency_is_configured(): void
    {
        $owner = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'price' => 10, 'currency' => 'USD']);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune Messiah', 'ean' => '9780000000002', 'price' => 10, 'currency' => 'EUR']);

        $this->assertFalse($this->statsFor($library->id)['currency_mismatch']);
    }

    public function test_mismatch_is_flagged_when_an_items_currency_disagrees_with_the_configured_default(): void
    {
        $owner = $this->actingAsAdmin();
        SystemSetting::set('statistics.default_currency', 'EUR');
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'price' => 10, 'currency' => 'EUR']);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune Messiah', 'ean' => '9780000000002', 'price' => 10, 'currency' => 'USD']);

        $this->assertTrue($this->statsFor($library->id)['currency_mismatch']);
    }

    public function test_no_mismatch_when_every_items_currency_matches_the_configured_default(): void
    {
        $owner = $this->actingAsAdmin();
        SystemSetting::set('statistics.default_currency', 'EUR');
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'price' => 10, 'currency' => 'EUR']);

        $this->assertFalse($this->statsFor($library->id)['currency_mismatch']);
    }

    /** An item with no currency recorded at all (e.g. pre-#58, or a provider that never mapped one) is not itself a mismatch. */
    public function test_an_item_with_no_currency_at_all_is_not_a_mismatch(): void
    {
        $owner = $this->actingAsAdmin();
        SystemSetting::set('statistics.default_currency', 'EUR');
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'price' => 10]);

        $this->assertFalse($this->statsFor($library->id)['currency_mismatch']);
    }

    /** GitHub issue #105 — `default_currency` rides along on every /statistics row so the frontend can show total_value with a currency symbol. */
    public function test_statistics_includes_the_configured_default_currency(): void
    {
        $owner = $this->actingAsAdmin();
        SystemSetting::set('statistics.default_currency', 'EUR');
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $this->assertSame('EUR', $this->statsFor($library->id)['default_currency']);
    }

    public function test_statistics_default_currency_is_null_when_unconfigured(): void
    {
        $owner = $this->actingAsAdmin();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $this->assertNull($this->statsFor($library->id)['default_currency']);
    }

    public function test_the_admin_settings_index_includes_the_current_default_currency(): void
    {
        $this->actingAsAdmin();
        SystemSetting::set('statistics.default_currency', 'EUR');

        $response = $this->getJson('/api/admin/settings');

        $response->assertOk()->assertJsonPath('statistics.default_currency', 'EUR');
    }

    public function test_the_default_currency_is_null_when_unconfigured(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/admin/settings');

        $response->assertOk()->assertJsonPath('statistics.default_currency', null);
    }

    public function test_an_admin_can_set_the_default_currency(): void
    {
        $this->actingAsAdmin();

        $response = $this->putJson('/api/admin/settings/statistics', ['default_currency' => 'EUR']);

        $response->assertOk()->assertJson(['default_currency' => 'EUR']);
        $this->assertSame('EUR', SystemSetting::get('statistics.default_currency'));
    }

    public function test_an_admin_can_clear_the_default_currency(): void
    {
        $this->actingAsAdmin();
        SystemSetting::set('statistics.default_currency', 'EUR');

        $response = $this->putJson('/api/admin/settings/statistics', ['default_currency' => null]);

        $response->assertOk()->assertJson(['default_currency' => null]);
        $this->assertNull(SystemSetting::get('statistics.default_currency'));
    }

    public function test_setting_a_default_currency_longer_than_three_characters_is_rejected(): void
    {
        $this->actingAsAdmin();

        $response = $this->putJson('/api/admin/settings/statistics', ['default_currency' => 'DOLLARS']);

        $response->assertStatus(422);
    }

    public function test_a_non_admin_cannot_set_the_default_currency(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        $response = $this->putJson('/api/admin/settings/statistics', ['default_currency' => 'EUR']);

        $response->assertStatus(403);
        $this->assertNull(SystemSetting::get('statistics.default_currency'));
    }
}
