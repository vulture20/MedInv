<?php

namespace Tests\Feature;

use App\Domain\Templates\BundledTemplateRegistry;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Covers BundledTemplateRegistry (templates/*.json at the project root,
 * pre-installed on fresh boot via DatabaseSeeder) and the admin-facing
 * (re)install endpoints built on top of it. Deliberate structural mirror
 * of BundledLanguagePackTest — see that class's docblock for the shared
 * reasoning (a temporary fixture directory rather than the real repo
 * files, so this suite doesn't need updating whenever the actual bundled
 * templates' content changes).
 */
class BundledTemplateTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = sys_get_temp_dir().'/medinv-templates-test-'.uniqid();
        mkdir($this->fixtureDir);
        config(['medinv.templates_path' => $this->fixtureDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureDir);
        parent::tearDown();
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

    private function writeFixture(string $filename, array $data): void
    {
        file_put_contents("{$this->fixtureDir}/{$filename}", json_encode($data));
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_available_lists_every_valid_bundled_file(): void
    {
        $this->writeFixture('solarized.json', ['code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors()]);
        $this->writeFixture('sepia.json', ['code' => 'sepia', 'name' => 'Sepia', 'colors' => $this->validColors(['color-bg' => '#f4ecd8'])]);

        $available = app(BundledTemplateRegistry::class)->available();

        $this->assertEqualsCanonicalizing(
            [['code' => 'solarized', 'name' => 'Solarized'], ['code' => 'sepia', 'name' => 'Sepia']],
            $available,
        );
    }

    public function test_available_skips_a_malformed_file_without_failing(): void
    {
        $this->writeFixture('solarized.json', ['code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors()]);
        file_put_contents("{$this->fixtureDir}/broken.json", '{not valid json');

        $available = app(BundledTemplateRegistry::class)->available();

        $this->assertSame([['code' => 'solarized', 'name' => 'Solarized']], $available);
    }

    public function test_available_skips_a_file_with_incomplete_colors(): void
    {
        $this->writeFixture('solarized.json', ['code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors()]);
        $incomplete = $this->validColors();
        unset($incomplete['color-accent']);
        $this->writeFixture('half-baked.json', ['code' => 'half-baked', 'name' => 'Half Baked', 'colors' => $incomplete]);

        $available = app(BundledTemplateRegistry::class)->available();

        $this->assertSame([['code' => 'solarized', 'name' => 'Solarized']], $available);
    }

    public function test_install_missing_creates_every_bundled_template_that_does_not_exist_yet(): void
    {
        $this->writeFixture('solarized.json', ['code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors()]);

        app(BundledTemplateRegistry::class)->installMissing();

        $this->assertDatabaseHas((new Template)->getTable(), ['code' => 'solarized', 'name' => 'Solarized']);
    }

    public function test_install_missing_never_overwrites_a_template_an_admin_has_edited(): void
    {
        $this->writeFixture('solarized.json', ['code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors()]);
        Template::query()->create(['code' => 'solarized', 'name' => 'Custom name', 'colors' => $this->validColors(['color-bg' => '#000000'])]);

        app(BundledTemplateRegistry::class)->installMissing();

        $this->assertDatabaseHas((new Template)->getTable(), ['code' => 'solarized', 'name' => 'Custom name']);
    }

    public function test_bundled_endpoint_flags_which_templates_are_already_installed(): void
    {
        $this->actingAsAdmin();
        $this->writeFixture('solarized.json', ['code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors()]);
        $this->writeFixture('sepia.json', ['code' => 'sepia', 'name' => 'Sepia', 'colors' => $this->validColors()]);
        Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors()]);

        $response = $this->getJson('/api/admin/templates/bundled');

        $response->assertOk();
        $response->assertJson([
            ['code' => 'sepia', 'name' => 'Sepia', 'installed' => false],
            ['code' => 'solarized', 'name' => 'Solarized', 'installed' => true],
        ]);
    }

    public function test_an_ordinary_user_cannot_list_bundled_templates(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        $this->getJson('/api/admin/templates/bundled')->assertForbidden();
    }

    public function test_admin_can_install_a_bundled_template(): void
    {
        $this->actingAsAdmin();
        $this->writeFixture('solarized.json', ['code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors()]);

        $response = $this->postJson('/api/admin/templates/bundled/solarized');

        $response->assertCreated();
        $this->assertDatabaseHas((new Template)->getTable(), ['code' => 'solarized', 'name' => 'Solarized']);
    }

    public function test_installing_a_bundled_template_overwrites_a_previously_edited_row(): void
    {
        $this->actingAsAdmin();
        $this->writeFixture('solarized.json', ['code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors(['color-bg' => '#fdf6e3'])]);
        Template::query()->create(['code' => 'solarized', 'name' => 'Edited name', 'colors' => $this->validColors(['color-bg' => '#000000'])]);

        $this->postJson('/api/admin/templates/bundled/solarized')->assertCreated();

        $template = Template::query()->where('code', 'solarized')->firstOrFail();
        $this->assertSame('Solarized', $template->name);
        $this->assertSame('#fdf6e3', $template->colors['color-bg']);
    }

    public function test_installing_an_unknown_bundled_code_404s(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/templates/bundled/xx')->assertNotFound();
    }

    public function test_an_ordinary_user_cannot_install_a_bundled_template(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);
        $this->writeFixture('solarized.json', ['code' => 'solarized', 'name' => 'Solarized', 'colors' => $this->validColors()]);

        $this->postJson('/api/admin/templates/bundled/solarized')->assertForbidden();
        $this->assertDatabaseMissing((new Template)->getTable(), ['code' => 'solarized']);
    }
}
