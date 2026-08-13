<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupService;
use App\Domain\ExportImport\ExportImportService;
use App\Models\MetadataPlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * GitHub issue #29's second finding: metadata_plugins (provider
 * enable/priority/config — including e.g. UpcMdbProvider's api_key) was
 * entirely absent from backups, so a restore onto a fresh instance left
 * every plugin at its just-seeded default rather than the admin's actual
 * configuration. Rides along on the same includeUsers/restoreSettings
 * opt-in as user accounts, since an API key is just as sensitive as a
 * password hash.
 */
class BackupPluginConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_includes_metadata_plugins_with_their_config(): void
    {
        Storage::fake('local');
        MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.upcmdb',
            'name' => 'UPCMDB',
            'media_type' => 'dvd_bluray',
            'enabled' => true,
            'priority' => 2,
            'config' => ['api_key' => 'secret-key-123'],
        ]);

        $backup = app(BackupService::class)->create();
        $manifest = $this->readManifest($backup->filename);

        $this->assertArrayHasKey('metadata_plugins', $manifest);
        $plugin = collect($manifest['metadata_plugins'])->firstWhere('provider_key', 'dvd_bluray.upcmdb');
        $this->assertSame('secret-key-123', $plugin['config']['api_key']);
        $this->assertSame(2, $plugin['priority']);
        $this->assertTrue($plugin['enabled']);
    }

    public function test_ordinary_export_does_not_leak_plugin_config(): void
    {
        MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.upcmdb',
            'name' => 'UPCMDB',
            'media_type' => 'dvd_bluray',
            'enabled' => true,
            'config' => ['api_key' => 'secret-key-123'],
        ]);

        $export = app(ExportImportService::class)->exportLibraries(null);

        $this->assertArrayNotHasKey('metadata_plugins', $export);
    }

    public function test_restoring_with_restore_settings_upserts_plugin_config_by_provider_key(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);
        MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.upcmdb',
            'name' => 'UPCMDB',
            'media_type' => 'dvd_bluray',
            'enabled' => false,
            'config' => ['api_key' => 'old-key'],
        ]);

        $data = [
            'system_settings' => [],
            'libraries' => [],
            'metadata_plugins' => [
                [
                    'provider_key' => 'dvd_bluray.upcmdb',
                    'name' => 'UPCMDB',
                    'media_type' => 'dvd_bluray',
                    'enabled' => true,
                    'priority' => 5,
                    'config' => ['api_key' => 'restored-key'],
                ],
            ],
        ];

        $result = app(ExportImportService::class)->importLibraries($data, $admin, restoreSettings: true);

        $this->assertSame(['dvd_bluray.upcmdb'], $result['plugins_restored']);
        $plugin = MetadataPlugin::query()->where('provider_key', 'dvd_bluray.upcmdb')->first();
        $this->assertTrue((bool) $plugin->enabled);
        $this->assertSame(5, $plugin->priority);
        $this->assertSame('restored-key', $plugin->config['api_key']);
        // Upserted, not duplicated.
        $this->assertSame(1, MetadataPlugin::query()->where('provider_key', 'dvd_bluray.upcmdb')->count());
    }

    public function test_restoring_without_restore_settings_leaves_plugin_config_untouched(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);
        MetadataPlugin::query()->create([
            'provider_key' => 'dvd_bluray.upcmdb',
            'name' => 'UPCMDB',
            'media_type' => 'dvd_bluray',
            'enabled' => false,
            'config' => ['api_key' => 'untouched-key'],
        ]);

        $data = [
            'system_settings' => [],
            'libraries' => [],
            'metadata_plugins' => [
                ['provider_key' => 'dvd_bluray.upcmdb', 'name' => 'UPCMDB', 'media_type' => 'dvd_bluray', 'enabled' => true, 'priority' => 5, 'config' => ['api_key' => 'should-not-apply']],
            ],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin);

        $plugin = MetadataPlugin::query()->where('provider_key', 'dvd_bluray.upcmdb')->first();
        $this->assertFalse((bool) $plugin->enabled);
        $this->assertSame('untouched-key', $plugin->config['api_key']);
    }

    private function readManifest(string $filename): array
    {
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path('backups/'.$filename));
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        return $manifest;
    }
}
