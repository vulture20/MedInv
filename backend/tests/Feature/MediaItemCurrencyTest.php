<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub issue #58 (follow-up): `currency` records which ISO 4217 currency
 * a `price` is in — a deliberate extension beyond briefing 6.1-6.3's fixed
 * attribute set (per explicit user request), see the migration that added
 * it for why. Exercised here only through MediaBook/book — MediaCd/
 * MediaDvdBluray share the exact same rulesFor()/#[Fillable] pattern, no
 * media-type-specific behavior to cover separately.
 */
class MediaItemCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_price_and_currency_can_be_set_on_manual_creation(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->postJson("/api/libraries/{$library->id}/items", [
            'title' => 'Dune',
            'ean' => '9780000000001',
            'price' => 24.99,
            'currency' => 'EUR',
        ]);

        $response->assertCreated();
        $this->assertSame('EUR', $response->json('currency'));
        $this->assertDatabaseHas((new MediaBook)->getTable(), ['ean' => '9780000000001', 'currency' => 'EUR']);
    }

    public function test_currency_can_be_updated_and_cleared(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create([
            'library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'price' => 24.99, 'currency' => 'USD',
        ]);

        $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['currency' => 'EUR'])->assertOk();
        $this->assertSame('EUR', $item->fresh()->currency);

        $this->putJson("/api/libraries/{$library->id}/items/{$item->id}", ['currency' => null])->assertOk();
        $this->assertNull($item->fresh()->currency);
    }

    public function test_currency_longer_than_three_characters_is_rejected(): void
    {
        $owner = $this->actingAsUser();
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);

        $response = $this->postJson("/api/libraries/{$library->id}/items", [
            'title' => 'Dune',
            'ean' => '9780000000001',
            'currency' => 'DOLLARS',
        ]);

        $response->assertStatus(422);
    }
}
