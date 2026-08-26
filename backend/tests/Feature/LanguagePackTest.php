<?php

namespace Tests\Feature;

use App\Models\LanguagePack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * GitHub issue #12: admin-managed language packs beyond the bundled German/
 * English. Reading (index/show) is fully public — a visitor's translations
 * must be loadable on the login screen itself, before authentication — the
 * enforcement this issue actually asks for is that only admins can
 * create/update/delete a pack.
 */
class LanguagePackTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    // --- public reads ---

    public function test_index_is_reachable_without_any_authentication(): void
    {
        LanguagePack::query()->create(['code' => 'fr', 'name' => 'Français', 'translations' => ['common' => ['name' => 'Nom']]]);

        $response = $this->getJson('/api/languages');

        $response->assertOk();
        $response->assertJson([['code' => 'fr', 'name' => 'Français']]);
        // Only code/name in the list — the full translations blob is show()'s job.
        $response->assertJsonMissingPath('0.translations');
    }

    public function test_show_is_reachable_without_any_authentication_and_returns_the_full_translations(): void
    {
        LanguagePack::query()->create(['code' => 'fr', 'name' => 'Français', 'translations' => ['common' => ['name' => 'Nom']]]);

        $response = $this->getJson('/api/languages/fr');

        $response->assertOk();
        $response->assertJsonPath('translations.common.name', 'Nom');
    }

    public function test_show_binds_on_code_not_the_numeric_id(): void
    {
        $pack = LanguagePack::query()->create(['code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b']]);

        $this->getJson("/api/languages/{$pack->id}")->assertNotFound();
        $this->getJson('/api/languages/fr')->assertOk();
    }

    public function test_show_404s_for_an_unknown_code(): void
    {
        $this->getJson('/api/languages/xx')->assertNotFound();
    }

    // --- admin-only writes ---

    public function test_an_admin_can_create_a_language_pack(): void
    {
        $this->actingAsUser('admin');

        $response = $this->postJson('/api/admin/languages', [
            'code' => 'fr', 'name' => 'Français', 'translations' => ['common' => ['name' => 'Nom']],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas((new LanguagePack)->getTable(), ['code' => 'fr', 'name' => 'Français']);
    }

    public function test_an_ordinary_user_cannot_create_a_language_pack(): void
    {
        $this->actingAsUser('user');

        $response = $this->postJson('/api/admin/languages', [
            'code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b'],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing((new LanguagePack)->getTable(), ['code' => 'fr']);
    }

    public function test_a_guest_cannot_create_a_language_pack(): void
    {
        $this->actingAsUser('guest');

        $response = $this->postJson('/api/admin/languages', [
            'code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b'],
        ]);

        $response->assertForbidden();
    }

    public function test_an_unauthenticated_request_cannot_create_a_language_pack(): void
    {
        $response = $this->postJson('/api/admin/languages', [
            'code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b'],
        ]);

        $response->assertUnauthorized();
    }

    #[DataProvider('reservedCodeProvider')]
    public function test_the_reserved_bundled_codes_are_rejected_case_insensitively(string $code): void
    {
        $this->actingAsUser('admin');

        $response = $this->postJson('/api/admin/languages', [
            'code' => $code, 'name' => 'Whatever', 'translations' => ['a' => 'b'],
        ]);

        $response->assertStatus(422);
        // GitHub issue #198 — a dedicated, translated error_code instead of Laravel's raw Rule::notIn message.
        $this->assertSame('code_reserved', $response->json('error_code'));
        $this->assertDatabaseMissing((new LanguagePack)->getTable(), ['code' => strtolower($code)]);
    }

    public static function reservedCodeProvider(): array
    {
        return [
            ['de'], ['DE'], ['De'],
            ['en'], ['EN'], ['En'],
        ];
    }

    public function test_a_duplicate_code_is_rejected(): void
    {
        LanguagePack::query()->create(['code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b']]);
        $this->actingAsUser('admin');

        $response = $this->postJson('/api/admin/languages', [
            'code' => 'fr', 'name' => 'Français (again)', 'translations' => ['a' => 'b'],
        ]);

        $response->assertStatus(422);
        // GitHub issue #198 — a dedicated, translated error_code instead of Laravel's raw `unique` message.
        $this->assertSame('code_taken', $response->json('error_code'));
        $this->assertSame(1, LanguagePack::query()->where('code', 'fr')->count());
    }

    /**
     * Regression guard: a top-level 'array' rule on `translations` combined
     * with a nested 'translations.*' rule would make Laravel treat the
     * field as "structured" and silently drop every other validated key
     * (code, name) from validate()'s output — the same pitfall already
     * documented on MetadataController::import()'s `attributes` field.
     * There is deliberately no 'translations.*' rule in store(); this just
     * confirms every field the request sent actually made it through.
     */
    public function test_every_field_survives_validation_intact(): void
    {
        $this->actingAsUser('admin');

        $response = $this->postJson('/api/admin/languages', [
            'code' => 'fr',
            'name' => 'Français',
            'translations' => ['common' => ['name' => 'Nom'], 'nav' => ['home' => 'Accueil']],
        ]);

        $response->assertCreated();
        $pack = LanguagePack::query()->where('code', 'fr')->firstOrFail();
        $this->assertSame('Français', $pack->name);
        $this->assertSame(['common' => ['name' => 'Nom'], 'nav' => ['home' => 'Accueil']], $pack->translations);
    }

    public function test_an_admin_can_update_name_and_translations_but_not_the_code(): void
    {
        LanguagePack::query()->create(['code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b']]);
        $this->actingAsUser('admin');

        $response = $this->putJson('/api/admin/languages/fr', [
            'code' => 'xx', 'name' => 'French', 'translations' => ['a' => 'c'],
        ]);

        $response->assertOk();
        $pack = LanguagePack::query()->where('code', 'fr')->firstOrFail();
        $this->assertSame('French', $pack->name);
        $this->assertSame(['a' => 'c'], $pack->translations);
        $this->assertDatabaseMissing((new LanguagePack)->getTable(), ['code' => 'xx']);
    }

    public function test_an_ordinary_user_cannot_update_a_language_pack(): void
    {
        LanguagePack::query()->create(['code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b']]);
        $this->actingAsUser('user');

        $response = $this->putJson('/api/admin/languages/fr', ['name' => 'Hacked']);

        $response->assertForbidden();
        $this->assertDatabaseHas((new LanguagePack)->getTable(), ['code' => 'fr', 'name' => 'Français']);
    }

    public function test_an_admin_can_delete_a_language_pack(): void
    {
        LanguagePack::query()->create(['code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b']]);
        $this->actingAsUser('admin');

        $response = $this->deleteJson('/api/admin/languages/fr');

        $response->assertNoContent();
        $this->assertDatabaseMissing((new LanguagePack)->getTable(), ['code' => 'fr']);
    }

    public function test_an_ordinary_user_cannot_delete_a_language_pack(): void
    {
        LanguagePack::query()->create(['code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b']]);
        $this->actingAsUser('user');

        $response = $this->deleteJson('/api/admin/languages/fr');

        $response->assertForbidden();
        $this->assertDatabaseHas((new LanguagePack)->getTable(), ['code' => 'fr']);
    }
}
