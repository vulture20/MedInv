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

    private const SAMPLE_CSS = ":root {\n  --color-bg: #fdf6e3;\n}\n";

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
        $this->writeFixture('solarized.json', ['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);
        $this->writeFixture('sepia.json', ['code' => 'sepia', 'name' => 'Sepia', 'css' => self::SAMPLE_CSS]);

        $available = app(BundledTemplateRegistry::class)->available();

        $this->assertEqualsCanonicalizing(
            [['code' => 'solarized', 'name' => 'Solarized'], ['code' => 'sepia', 'name' => 'Sepia']],
            $available,
        );
    }

    public function test_available_skips_a_malformed_file_without_failing(): void
    {
        $this->writeFixture('solarized.json', ['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);
        file_put_contents("{$this->fixtureDir}/broken.json", '{not valid json');

        $available = app(BundledTemplateRegistry::class)->available();

        $this->assertSame([['code' => 'solarized', 'name' => 'Solarized']], $available);
    }

    public function test_available_skips_a_file_with_empty_css(): void
    {
        $this->writeFixture('solarized.json', ['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);
        $this->writeFixture('empty.json', ['code' => 'empty', 'name' => 'Empty', 'css' => '']);

        $available = app(BundledTemplateRegistry::class)->available();

        $this->assertSame([['code' => 'solarized', 'name' => 'Solarized']], $available);
    }

    public function test_available_skips_a_file_missing_css_entirely(): void
    {
        $this->writeFixture('solarized.json', ['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);
        $this->writeFixture('no-css.json', ['code' => 'no-css', 'name' => 'No CSS']);

        $available = app(BundledTemplateRegistry::class)->available();

        $this->assertSame([['code' => 'solarized', 'name' => 'Solarized']], $available);
    }

    public function test_install_missing_creates_every_bundled_template_that_does_not_exist_yet(): void
    {
        $this->writeFixture('solarized.json', ['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);

        app(BundledTemplateRegistry::class)->installMissing();

        $this->assertDatabaseHas((new Template)->getTable(), ['code' => 'solarized', 'name' => 'Solarized']);
    }

    public function test_install_missing_never_overwrites_a_template_an_admin_has_edited(): void
    {
        $this->writeFixture('solarized.json', ['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);
        Template::query()->create(['code' => 'solarized', 'name' => 'Custom name', 'css' => ':root { --color-bg: #000000; }']);

        app(BundledTemplateRegistry::class)->installMissing();

        $this->assertDatabaseHas((new Template)->getTable(), ['code' => 'solarized', 'name' => 'Custom name']);
    }

    public function test_bundled_endpoint_flags_which_templates_are_already_installed(): void
    {
        $this->actingAsAdmin();
        $this->writeFixture('solarized.json', ['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);
        $this->writeFixture('sepia.json', ['code' => 'sepia', 'name' => 'Sepia', 'css' => self::SAMPLE_CSS]);
        Template::query()->create(['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);

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
        $this->writeFixture('solarized.json', ['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);

        $response = $this->postJson('/api/admin/templates/bundled/solarized');

        $response->assertCreated();
        $this->assertDatabaseHas((new Template)->getTable(), ['code' => 'solarized', 'name' => 'Solarized']);
    }

    public function test_installing_a_bundled_template_overwrites_a_previously_edited_row(): void
    {
        $this->actingAsAdmin();
        $this->writeFixture('solarized.json', ['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);
        Template::query()->create(['code' => 'solarized', 'name' => 'Edited name', 'css' => ':root { --color-bg: #000000; }']);

        $this->postJson('/api/admin/templates/bundled/solarized')->assertCreated();

        $template = Template::query()->where('code', 'solarized')->firstOrFail();
        $this->assertSame('Solarized', $template->name);
        $this->assertSame(self::SAMPLE_CSS, $template->css);
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
        $this->writeFixture('solarized.json', ['code' => 'solarized', 'name' => 'Solarized', 'css' => self::SAMPLE_CSS]);

        $this->postJson('/api/admin/templates/bundled/solarized')->assertForbidden();
        $this->assertDatabaseMissing((new Template)->getTable(), ['code' => 'solarized']);
    }
}
