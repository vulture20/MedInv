<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupService;
use App\Domain\ExportImport\ExportImportService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Covers the gap reported against BackupService::create(): a backup must
 * always carry the full system configuration (even settings an admin never
 * explicitly saved, see SystemSetting::defaults()) and every user account,
 * not just library/media data.
 */
class BackupExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_includes_all_system_settings_even_when_never_explicitly_saved(): void
    {
        Storage::fake('local');

        // Deliberately untouched — no SystemSetting::set() call for any key,
        // simulating a fresh instance where the admin never opened the
        // security/backup-schedule forms.
        $backup = app(BackupService::class)->create();

        $manifest = $this->readManifest($backup->filename);

        $this->assertSame([
            'mail.encryption',
            'backup.interval_mode',
            'security.throttle_max_attempts',
            'security.throttle_window_minutes',
            'security.throttle_lock_minutes',
            'covers.cleanup_enabled',
            'timezone',
            'loglevel',
            'locale.default_language',
        ], array_keys($manifest['system_settings']));
        $this->assertSame(6, $manifest['system_settings']['security.throttle_max_attempts']);
    }

    public function test_backup_includes_all_users_with_their_attributes(): void
    {
        Storage::fake('local');

        $user = User::factory()->create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'level' => 'user',
        ]);

        $backup = app(BackupService::class)->create();
        $manifest = $this->readManifest($backup->filename);

        $this->assertArrayHasKey('users', $manifest);
        $exported = collect($manifest['users'])->firstWhere('email', 'jane@example.com');

        $this->assertNotNull($exported);
        $this->assertSame('Jane Doe', $exported['name']);
        $this->assertSame($user->password, $exported['password']);
        $this->assertSame('user', $exported['level']);
        $this->assertArrayNotHasKey('id', $exported);
    }

    public function test_ordinary_export_does_not_leak_user_accounts(): void
    {
        User::factory()->create();

        $export = app(ExportImportService::class)->exportLibraries(null);

        $this->assertArrayNotHasKey('users', $export);
    }

    public function test_restoring_a_backup_with_restore_settings_recreates_users(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);

        $data = [
            'system_settings' => [],
            'libraries' => [],
            'users' => [
                [
                    'name' => 'Restored User',
                    'email' => 'restored@example.com',
                    'password' => 'not-a-real-hash-but-not-bcrypt-either',
                    'level' => 'user',
                    'is_active' => true,
                    'is_protected' => false,
                    'preferred_language' => 'de',
                    'preferred_template' => 'light',
                ],
            ],
        ];

        $result = app(ExportImportService::class)->importLibraries($data, $admin, restoreSettings: true);

        $this->assertSame(['restored@example.com'], $result['users_restored']);
        $this->assertDatabaseHas((new User)->getTable(), ['email' => 'restored@example.com', 'name' => 'Restored User']);
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
