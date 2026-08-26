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
 * why). `css` is a raw CSS text blob (not a fixed color-key object — see
 * the 2026_08_15_100000_replace_template_colors_with_css migration), so
 * unlike LanguagePackTest there's no required-key validation to cover
 * here, just "non-empty string within the size limit".
 */
class TemplateTest extends TestCase
{
    use RefreshDatabase;

    // No trailing newline — Laravel's TrimStrings middleware trims request
    // string inputs, so a fixture with one wouldn't round-trip unchanged.
    private const SAMPLE_CSS = ":root {\n  --color-bg: #fdf6e3;\n  --color-text: #073642;\n}";

    private function actingAsUser(string $level = 'user'): User
    {
        $user = User::factory()->create(['level' => $level, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    // --- public reads ---

    public function test_index_is_reachable_without_any_authentication(): void
    {
        Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);

        $response = $this->getJson('/api/templates');

        $response->assertOk();
        $response->assertJson([['code' => 'solarized', 'name' => 'Solarized']]);
        // Only code/name in the list — the full css blob is show()'s job.
        $response->assertJsonMissingPath('0.css');
    }

    public function test_show_is_reachable_without_any_authentication_and_returns_the_full_css(): void
    {
        Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);

        $response = $this->getJson('/api/templates/solarized');

        $response->assertOk();
        $response->assertJsonPath('css', self::SAMPLE_CSS);
    }

    public function test_show_binds_on_code_not_the_numeric_id(): void
    {
        $template = Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);

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
            'code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas((new Template)->getTable(), ['code' => 'solarized', 'name' => 'Solarized']);
    }

    public function test_an_ordinary_user_cannot_create_a_template(): void
    {
        $this->actingAsUser('user');

        $response = $this->postJson('/api/admin/templates', [
            'code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing((new Template)->getTable(), ['code' => 'solarized']);
    }

    public function test_a_guest_cannot_create_a_template(): void
    {
        $this->actingAsUser('guest');

        $response = $this->postJson('/api/admin/templates', [
            'code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS,
        ]);

        $response->assertForbidden();
    }

    public function test_an_unauthenticated_request_cannot_create_a_template(): void
    {
        $response = $this->postJson('/api/admin/templates', [
            'code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS,
        ]);

        $response->assertUnauthorized();
    }

    #[DataProvider('reservedCodeProvider')]
    public function test_the_reserved_bundled_codes_are_rejected_case_insensitively(string $code): void
    {
        $this->actingAsUser('admin');

        $response = $this->postJson('/api/admin/templates', [
            'code' => $code, 'name' => 'Whatever', 'css' => self::SAMPLE_CSS,
        ]);

        $response->assertStatus(422);
        // GitHub issue #198 — a dedicated, translated error_code instead of Laravel's raw Rule::notIn message.
        $this->assertSame('code_reserved', $response->json('error_code'));
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
        Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);
        $this->actingAsUser('admin');

        $response = $this->postJson('/api/admin/templates', [
            'code' => 'solarized', 'name' => 'Solarized (again)', 'css' => self::SAMPLE_CSS,
        ]);

        $response->assertStatus(422);
        // GitHub issue #198 — a dedicated, translated error_code instead of Laravel's raw `unique` message.
        $this->assertSame('code_taken', $response->json('error_code'));
        $this->assertSame(1, Template::query()->where('code', 'solarized')->count());
    }

    public function test_creating_a_template_with_empty_css_is_rejected(): void
    {
        $this->actingAsUser('admin');

        $response = $this->postJson('/api/admin/templates', [
            'code' => 'solarized', 'name' => 'Solarized', 'css' => '',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing((new Template)->getTable(), ['code' => 'solarized']);
    }

    public function test_creating_a_template_with_css_over_the_length_limit_is_rejected(): void
    {
        $this->actingAsUser('admin');

        $response = $this->postJson('/api/admin/templates', [
            'code' => 'solarized', 'name' => 'Solarized', 'css' => str_repeat('a', 200001),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing((new Template)->getTable(), ['code' => 'solarized']);
    }

    public function test_every_field_survives_validation_intact(): void
    {
        $this->actingAsUser('admin');

        $response = $this->postJson('/api/admin/templates', [
            'code' => 'solarized',
            'name' => 'Solarized',
            'css' => self::SAMPLE_CSS,
        ]);

        $response->assertCreated();
        $template = Template::query()->where('code', 'solarized')->firstOrFail();
        $this->assertSame('Solarized', $template->name);
        $this->assertSame(self::SAMPLE_CSS, $template->css);
    }

    public function test_an_admin_can_update_name_and_css_but_not_the_code(): void
    {
        Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);
        $this->actingAsUser('admin');

        $newCss = ":root {\n  --color-bg: #ffffff;\n}";
        $response = $this->putJson('/api/admin/templates/solarized', [
            'code' => 'xx', 'name' => 'Solarized Light', 'css' => $newCss,
        ]);

        $response->assertOk();
        $template = Template::query()->where('code', 'solarized')->firstOrFail();
        $this->assertSame('Solarized Light', $template->name);
        $this->assertSame($newCss, $template->css);
        $this->assertDatabaseMissing((new Template)->getTable(), ['code' => 'xx']);
    }

    public function test_updating_a_template_to_have_empty_css_is_rejected(): void
    {
        Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);
        $this->actingAsUser('admin');

        $response = $this->putJson('/api/admin/templates/solarized', ['css' => '']);

        $response->assertStatus(422);
        $this->assertSame(self::SAMPLE_CSS, Template::query()->where('code', 'solarized')->firstOrFail()->css);
    }

    public function test_an_ordinary_user_cannot_update_a_template(): void
    {
        Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);
        $this->actingAsUser('user');

        $response = $this->putJson('/api/admin/templates/solarized', ['name' => 'Hacked']);

        $response->assertForbidden();
        $this->assertDatabaseHas((new Template)->getTable(), ['code' => 'solarized', 'name' => 'Solarized']);
    }

    public function test_an_admin_can_delete_a_template(): void
    {
        Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);
        $this->actingAsUser('admin');

        $response = $this->deleteJson('/api/admin/templates/solarized');

        $response->assertNoContent();
        $this->assertDatabaseMissing((new Template)->getTable(), ['code' => 'solarized']);
    }

    public function test_an_ordinary_user_cannot_delete_a_template(): void
    {
        Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);
        $this->actingAsUser('user');

        $response = $this->deleteJson('/api/admin/templates/solarized');

        $response->assertForbidden();
        $this->assertDatabaseHas((new Template)->getTable(), ['code' => 'solarized']);
    }
}
