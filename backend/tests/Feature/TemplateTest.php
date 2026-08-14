<?php

namespace Tests\Feature;

use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * GitHub issue #11: admin-managed UI templates beyond the bundled
 * light/dark. Reading (index/show) is fully public — a visitor's chosen
 * template must be renderable on the login screen itself, before
 * authentication — the enforcement this issue actually asks for is that
 * only admins can create/update/delete a template. Deliberate structural
 * mirror of LanguagePackTest (see TemplateController's own docblock for
 * why), with REQUIRED_COLOR_KEYS validation as the one real difference
 * from a language pack's `translations`.
 */
class TemplateTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function validColors(array $overrides = []): array
    {
        return array_merge([
            'color-bg' => '#fdf6e3',
            'color-surface' => '#eee8d5',
            'color-text' => '#073642',
            'color-text-muted' => '#657b83',
            'color-border' => '#93a1a1',
            'color-accent' => '#268bd2',
            'color-danger' => '#dc322f',
            'color-danger-bg' => '#fdf0ef',
            'color-scheme' => 'light',
        ], $overrides);
    }

    // --- public reads ---

    public function test_index_is_reachable_without_any_authentication(): void
    {
        Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors()]);

        $response = $this->getJson('/api/templates');

        $response->assertOk();
        $response->assertJson([['code' => 'solarized', 'name' => 'Solarized']]);
        // Only code/name in the list — the full colors blob is show()'s job.
        $response->assertJsonMissingPath('0.colors');
    }

    public function test_show_is_reachable_without_any_authentication_and_returns_the_full_colors(): void
    {
        Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors(['color-bg' => '#fdf6e3'])]);

        $response = $this->getJson('/api/templates/solarized');

        $response->assertOk();
        $response->assertJsonPath('colors.color-bg', '#fdf6e3');
    }

    public function test_show_binds_on_code_not_the_numeric_id(): void
    {
        $template = Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors()]);

        $this->getJson("/api/templates/{$template->id}")->assertNotFound();
        $this->getJson('/api/templates/solarized')->assertOk();
    }

    public function test_show_404s_for_an_unknown_code(): void
    {
        $this->getJson('/api/templates/xx')->assertNotFound();
    }

    // --- admin-only writes ---

    public function test_an_admin_can_create_a_template(): void
    {
        $this->actingAsUser('admin');

        $response = $this->postJson('/api/admin/templates', [
            'code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors(),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas((new Template)->getTable(), ['code' => 'solarized', 'name' => 'Solarized']);
    }

    public function test_an_ordinary_user_cannot_create_a_template(): void
    {
        $this->actingAsUser('user');

        $response = $this->postJson('/api/admin/templates', [
            'code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors(),
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing((new Template)->getTable(), ['code' => 'solarized']);
    }

    public function test_a_guest_cannot_create_a_template(): void
    {
        $this->actingAsUser('guest');

        $response = $this->postJson('/api/admin/templates', [
            'code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors(),
        ]);

        $response->assertForbidden();
    }

    public function test_an_unauthenticated_request_cannot_create_a_template(): void
    {
        $response = $this->postJson('/api/admin/templates', [
            'code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors(),
        ]);

        $response->assertUnauthorized();
    }

    #[DataProvider('reservedCodeProvider')]
    public function test_the_reserved_bundled_codes_are_rejected_case_insensitively(string $code): void
    {
        $this->actingAsUser('admin');

        $response = $this->postJson('/api/admin/templates', [
            'code' => $code, 'name' => 'Whatever', 'colors' => $this->validColors(),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing((new Template)->getTable(), ['code' => strtolower($code)]);
    }

    public static function reservedCodeProvider(): array
    {
        return [
            ['light'], ['LIGHT'], ['Light'],
            ['dark'], ['DARK'], ['Dark'],
        ];
    }

    public function test_a_duplicate_code_is_rejected(): void
    {
        Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors()]);
        $this->actingAsUser('admin');

        $response = $this->postJson('/api/admin/templates', [
            'code' => 'solarized', 'name' => 'Solarized (again)', 'colors' => $this->validColors(),
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, Template::query()->where('code', 'solarized')->count());
    }

    public function test_creating_a_template_missing_a_required_color_key_is_rejected(): void
    {
        $this->actingAsUser('admin');
        $incomplete = $this->validColors();
        unset($incomplete['color-danger']);

        $response = $this->postJson('/api/admin/templates', [
            'code' => 'solarized', 'name' => 'Solarized', 'colors' => $incomplete,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing((new Template)->getTable(), ['code' => 'solarized']);
    }

    /**
     * Regression guard: a top-level 'array' rule on `colors` combined with
     * a nested 'colors.*' rule would make Laravel treat the field as
     * "structured" and silently drop every other validated key (code,
     * name) from validate()'s output — the same pitfall already documented
     * on MetadataController::import()'s `attributes` field and
     * LanguagePackController::store()'s `translations`. There is
     * deliberately no 'colors.*' rule in store(); this just confirms every
     * field the request sent actually made it through.
     */
    public function test_every_field_survives_validation_intact(): void
    {
        $this->actingAsUser('admin');

        $response = $this->postJson('/api/admin/templates', [
            'code' => 'solarized',
            'name' => 'Solarized',
            'colors' => $this->validColors(),
        ]);

        $response->assertCreated();
        $template = Template::query()->where('code', 'solarized')->firstOrFail();
        $this->assertSame('Solarized', $template->name);
        $this->assertSame($this->validColors(), $template->colors);
    }

    public function test_an_admin_can_update_name_and_colors_but_not_the_code(): void
    {
        Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors()]);
        $this->actingAsUser('admin');

        $response = $this->putJson('/api/admin/templates/solarized', [
            'code' => 'xx', 'name' => 'Solarized Light', 'colors' => $this->validColors(['color-bg' => '#ffffff']),
        ]);

        $response->assertOk();
        $template = Template::query()->where('code', 'solarized')->firstOrFail();
        $this->assertSame('Solarized Light', $template->name);
        $this->assertSame('#ffffff', $template->colors['color-bg']);
        $this->assertDatabaseMissing((new Template)->getTable(), ['code' => 'xx']);
    }

    public function test_updating_a_template_to_have_incomplete_colors_is_rejected(): void
    {
        Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors()]);
        $this->actingAsUser('admin');
        $incomplete = $this->validColors();
        unset($incomplete['color-scheme']);

        $response = $this->putJson('/api/admin/templates/solarized', ['colors' => $incomplete]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('color-scheme', Template::query()->where('code', 'solarized')->firstOrFail()->colors);
    }

    public function test_an_ordinary_user_cannot_update_a_template(): void
    {
        Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors()]);
        $this->actingAsUser('user');

        $response = $this->putJson('/api/admin/templates/solarized', ['name' => 'Hacked']);

        $response->assertForbidden();
        $this->assertDatabaseHas((new Template)->getTable(), ['code' => 'solarized', 'name' => 'Solarized']);
    }

    public function test_an_admin_can_delete_a_template(): void
    {
        Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors()]);
        $this->actingAsUser('admin');

        $response = $this->deleteJson('/api/admin/templates/solarized');

        $response->assertNoContent();
        $this->assertDatabaseMissing((new Template)->getTable(), ['code' => 'solarized']);
    }

    public function test_an_ordinary_user_cannot_delete_a_template(): void
    {
        Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors()]);
        $this->actingAsUser('user');

        $response = $this->deleteJson('/api/admin/templates/solarized');

        $response->assertForbidden();
        $this->assertDatabaseHas((new Template)->getTable(), ['code' => 'solarized']);
    }
}
