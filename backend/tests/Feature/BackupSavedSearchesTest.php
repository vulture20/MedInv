<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupService;
use App\Domain\ExportImport\ExportImportService;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * GitHub issue #125: saved searches (GitHub issue #73's "nice to have")
 * were entirely absent from backups, so a restore onto a fresh instance —
 * or the same instance after MEDINV_RESTOREBACKUP resets it — silently
 * lost every user's saved filter combinations. Rides along on the same
 * includeUsers/restoreSettings opt-in as user accounts/metadata_plugins
 * (BackupPluginConfigTest.php's own precedent), since a saved search is
 * meaningless orphaned data without the user it belongs to.
 */
class BackupSavedSearchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_includes_saved_searches_keyed_by_user_email(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'owner@example.com']);
        SavedSearch::query()->create([
            'user_id' => $user->id,
            'name' => 'Cheap sci-fi books',
            'filters' => ['media_types' => ['book'], 'genre' => ['Sci-Fi'], 'price_max' => '10'],
        ]);

        $backup = app(BackupService::class)->create();
        $manifest = $this->readManifest($backup->filename);

        $this->assertArrayHasKey('saved_searches', $manifest);
        $saved = collect($manifest['saved_searches'])->firstWhere('name', 'Cheap sci-fi books');
        $this->assertSame('owner@example.com', $saved['user_email']);
        $this->assertSame(['book'], $saved['filters']['media_types']);
    }

    public function test_ordinary_export_does_not_include_saved_searches(): void
    {
        $user = User::factory()->create();
        SavedSearch::query()->create(['user_id' => $user->id, 'name' => 'Mine', 'filters' => []]);

        $export = app(ExportImportService::class)->exportLibraries(null);

        $this->assertArrayNotHasKey('saved_searches', $export);
    }

    public function test_restoring_with_restore_settings_recreates_saved_searches_by_user_email(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);
        $target = User::factory()->create(['email' => 'someone@example.com']);

        $data = [
            'libraries' => [],
            'saved_searches' => [
                ['user_email' => 'someone@example.com', 'name' => 'My search', 'filters' => ['query' => 'foo']],
            ],
        ];

        $result = app(ExportImportService::class)->importLibraries($data, $admin, restoreSettings: true);

        $this->assertSame(1, $result['saved_searches_restored']);
        $this->assertSame(0, $result['saved_searches_skipped']);
        $this->assertDatabaseHas((new SavedSearch)->getTable(), [
            'user_id' => $target->id, 'name' => 'My search',
        ]);
    }

    public function test_restoring_without_restore_settings_leaves_saved_searches_untouched(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);
        User::factory()->create(['email' => 'someone@example.com']);

        $data = [
            'libraries' => [],
            'saved_searches' => [
                ['user_email' => 'someone@example.com', 'name' => 'My search', 'filters' => []],
            ],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin);

        $this->assertSame(0, SavedSearch::query()->count());
    }

    /** MEDINV_RESTOREBACKUP restores unattended on every container start (briefing 9.3) — a repeated restore must not keep duplicating the same saved searches. */
    public function test_restoring_replaces_a_users_existing_saved_searches_instead_of_duplicating(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);
        $target = User::factory()->create(['email' => 'someone@example.com']);
        SavedSearch::query()->create(['user_id' => $target->id, 'name' => 'Stale, pre-restore search', 'filters' => []]);

        $data = [
            'libraries' => [],
            'saved_searches' => [
                ['user_email' => 'someone@example.com', 'name' => 'Fresh search', 'filters' => ['query' => 'foo']],
            ],
        ];

        // Restoring twice must not duplicate "Fresh search" either.
        $service = app(ExportImportService::class);
        $service->importLibraries($data, $admin, restoreSettings: true);
        $service->importLibraries($data, $admin, restoreSettings: true);

        $this->assertSame(['Fresh search'], SavedSearch::query()->where('user_id', $target->id)->pluck('name')->all());
    }

    /** A saved search belonging to a user this instance doesn't have (e.g. a since-deleted account on the source instance) is skipped, same tolerance restoreShares() already has for a stale scope=user email. */
    public function test_restoring_skips_a_saved_search_whose_user_email_matches_no_account(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);

        $data = [
            'libraries' => [],
            'saved_searches' => [
                ['user_email' => 'nobody@example.com', 'name' => 'Orphaned', 'filters' => []],
            ],
        ];

        $result = app(ExportImportService::class)->importLibraries($data, $admin, restoreSettings: true);

        $this->assertSame(0, $result['saved_searches_restored']);
        $this->assertSame(1, $result['saved_searches_skipped']);
        $this->assertSame(0, SavedSearch::query()->count());
    }

    /** A user's saved searches are left alone entirely when the backup contains none for them — restoring only ever clears what it's about to replace. */
    public function test_a_user_with_no_saved_searches_in_the_backup_keeps_their_own_untouched(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);
        $untouchedUser = User::factory()->create(['email' => 'untouched@example.com']);
        SavedSearch::query()->create(['user_id' => $untouchedUser->id, 'name' => 'Keep me', 'filters' => []]);

        $otherUser = User::factory()->create(['email' => 'someone@example.com']);
        $data = [
            'libraries' => [],
            'saved_searches' => [
                ['user_email' => 'someone@example.com', 'name' => 'Restored', 'filters' => []],
            ],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin, restoreSettings: true);

        $this->assertDatabaseHas((new SavedSearch)->getTable(), ['user_id' => $untouchedUser->id, 'name' => 'Keep me']);
        $this->assertDatabaseHas((new SavedSearch)->getTable(), ['user_id' => $otherUser->id, 'name' => 'Restored']);
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
