<?php

namespace Tests\Feature;

use App\Domain\Languages\BundledLanguagePackRegistry;
use App\Models\LanguagePack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Covers BundledLanguagePackRegistry (languagepacks/*.json at the project
 * root, pre-installed on fresh boot via DatabaseSeeder) and the admin-
 * facing (re)install endpoints built on top of it. Uses a temporary
 * fixture directory (medinv.languagepacks_path override) rather than the
 * real repo files, so this suite doesn't need updating whenever the actual
 * bundled packs' content changes.
 */
class BundledLanguagePackTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = sys_get_temp_dir().'/medinv-langpacks-test-'.uniqid();
        mkdir($this->fixtureDir);
        config(['medinv.languagepacks_path' => $this->fixtureDir]);
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
        $this->writeFixture('fr.json', ['code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b']]);
        $this->writeFixture('es.json', ['code' => 'es', 'name' => 'Español', 'translations' => ['a' => 'c']]);

        $available = app(BundledLanguagePackRegistry::class)->available();

        $this->assertEqualsCanonicalizing(
            [['code' => 'fr', 'name' => 'Français'], ['code' => 'es', 'name' => 'Español']],
            $available,
        );
    }

    public function test_available_skips_a_malformed_file_without_failing(): void
    {
        $this->writeFixture('fr.json', ['code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b']]);
        file_put_contents("{$this->fixtureDir}/broken.json", '{not valid json');

        $available = app(BundledLanguagePackRegistry::class)->available();

        $this->assertSame([['code' => 'fr', 'name' => 'Français']], $available);
    }

    public function test_install_missing_creates_every_bundled_pack_that_does_not_exist_yet(): void
    {
        $this->writeFixture('fr.json', ['code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b']]);

        app(BundledLanguagePackRegistry::class)->installMissing();

        $this->assertDatabaseHas((new LanguagePack)->getTable(), ['code' => 'fr', 'name' => 'Français']);
    }

    public function test_install_missing_never_overwrites_a_pack_an_admin_has_edited(): void
    {
        $this->writeFixture('fr.json', ['code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b']]);
        LanguagePack::query()->create(['code' => 'fr', 'name' => 'Custom name', 'translations' => ['a' => 'custom']]);

        app(BundledLanguagePackRegistry::class)->installMissing();

        $this->assertDatabaseHas((new LanguagePack)->getTable(), ['code' => 'fr', 'name' => 'Custom name']);
    }

    public function test_bundled_endpoint_flags_which_packs_are_already_installed(): void
    {
        $this->actingAsAdmin();
        $this->writeFixture('fr.json', ['code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b']]);
        $this->writeFixture('es.json', ['code' => 'es', 'name' => 'Español', 'translations' => ['a' => 'c']]);
        LanguagePack::query()->create(['code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b']]);

        $response = $this->getJson('/api/admin/languages/bundled');

        $response->assertOk();
        $response->assertJson([
            ['code' => 'es', 'name' => 'Español', 'installed' => false],
            ['code' => 'fr', 'name' => 'Français', 'installed' => true],
        ]);
    }

    public function test_an_ordinary_user_cannot_list_bundled_packs(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);

        $this->getJson('/api/admin/languages/bundled')->assertForbidden();
    }

    public function test_admin_can_install_a_bundled_pack(): void
    {
        $this->actingAsAdmin();
        $this->writeFixture('fr.json', ['code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b']]);

        $response = $this->postJson('/api/admin/languages/bundled/fr');

        $response->assertCreated();
        $this->assertDatabaseHas((new LanguagePack)->getTable(), ['code' => 'fr', 'name' => 'Français']);
    }

    public function test_installing_a_bundled_pack_overwrites_a_previously_edited_row(): void
    {
        $this->actingAsAdmin();
        $this->writeFixture('fr.json', ['code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'shipped']]);
        LanguagePack::query()->create(['code' => 'fr', 'name' => 'Edited name', 'translations' => ['a' => 'edited']]);

        $this->postJson('/api/admin/languages/bundled/fr')->assertCreated();

        $pack = LanguagePack::query()->where('code', 'fr')->firstOrFail();
        $this->assertSame('Français', $pack->name);
        $this->assertSame(['a' => 'shipped'], $pack->translations);
    }

    public function test_installing_an_unknown_bundled_code_404s(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/languages/bundled/xx')->assertNotFound();
    }

    public function test_an_ordinary_user_cannot_install_a_bundled_pack(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true]);
        $this->actingAs($user);
        $this->writeFixture('fr.json', ['code' => 'fr', 'name' => 'Français', 'translations' => ['a' => 'b']]);

        $this->postJson('/api/admin/languages/bundled/fr')->assertForbidden();
        $this->assertDatabaseMissing((new LanguagePack)->getTable(), ['code' => 'fr']);
    }
}
