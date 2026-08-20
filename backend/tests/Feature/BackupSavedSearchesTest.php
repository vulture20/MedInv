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
 * meaningless orphaned data without the user it belongs to — and, per
 * that same reasoning, nested as `saved_searches` under the owning user's
 * own entry in `users` (same shape `libraries[].shares` already uses for
 * "this collection belongs to the entity it's embedded in") rather than a
 * separate top-level list correlated by an email/id key.
 */
class BackupSavedSearchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_nests_saved_searches_under_their_owning_user(): void
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

        $exportedUser = collect($manifest['users'])->firstWhere('email', 'owner@example.com');
        $this->assertNotNull($exportedUser);
        $saved = collect($exportedUser['saved_searches'])->firstWhere('name', 'Cheap sci-fi books');
        $this->assertSame(['book'], $saved['filters']['media_types']);
        // Nested under the user — no separate correlation key needed on the entry itself.
        $this->assertArrayNotHasKey('user_email', $saved);
    }

    public function test_ordinary_export_does_not_include_saved_searches(): void
    {
        $user = User::factory()->create();
        SavedSearch::query()->create(['user_id' => $user->id, 'name' => 'Mine', 'filters' => []]);

        $export = app(ExportImportService::class)->exportLibraries(null);

        $this->assertArrayNotHasKey('users', $export);
    }

    public function test_restoring_with_restore_settings_recreates_saved_searches_for_the_matching_user(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);
        $target = User::factory()->create(['email' => 'someone@example.com']);

        $data = [
            'libraries' => [],
            'users' => [
                [
                    'name' => 'Someone', 'email' => 'someone@example.com', 'password' => 'hashed', 'level' => 'user', 'is_active' => true,
                    'saved_searches' => [
                        ['name' => 'My search', 'filters' => ['query' => 'foo']],
                    ],
                ],
            ],
        ];

        $result = app(ExportImportService::class)->importLibraries($data, $admin, restoreSettings: true);

        $this->assertSame(1, $result['saved_searches_restored']);
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
            'users' => [
                ['name' => 'Someone', 'email' => 'someone@example.com', 'password' => 'hashed', 'level' => 'user', 'is_active' => true, 'saved_searches' => [['name' => 'My search', 'filters' => []]]],
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
            'users' => [
                [
                    'name' => 'Someone', 'email' => 'someone@example.com', 'password' => 'hashed', 'level' => 'user', 'is_active' => true,
                    'saved_searches' => [['name' => 'Fresh search', 'filters' => ['query' => 'foo']]],
                ],
            ],
        ];

        // Restoring twice must not duplicate "Fresh search" either.
        $service = app(ExportImportService::class);
        $service->importLibraries($data, $admin, restoreSettings: true);
        $service->importLibraries($data, $admin, restoreSettings: true);

        $this->assertSame(['Fresh search'], SavedSearch::query()->where('user_id', $target->id)->pluck('name')->all());
    }

    /** A user with no saved_searches key in the backup at all keeps their own untouched — restoring only ever clears what it's about to replace, and never runs for a user not present in the payload to begin with. */
    public function test_a_user_absent_from_the_backup_keeps_their_own_saved_searches_untouched(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);
        $untouchedUser = User::factory()->create(['email' => 'untouched@example.com']);
        SavedSearch::query()->create(['user_id' => $untouchedUser->id, 'name' => 'Keep me', 'filters' => []]);

        $otherUser = User::factory()->create(['email' => 'someone@example.com']);
        $data = [
            'libraries' => [],
            'users' => [
                ['name' => 'Someone', 'email' => 'someone@example.com', 'password' => 'hashed', 'level' => 'user', 'is_active' => true, 'saved_searches' => [['name' => 'Restored', 'filters' => []]]],
            ],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin, restoreSettings: true);

        $this->assertDatabaseHas((new SavedSearch)->getTable(), ['user_id' => $untouchedUser->id, 'name' => 'Keep me']);
        $this->assertDatabaseHas((new SavedSearch)->getTable(), ['user_id' => $otherUser->id, 'name' => 'Restored']);
    }

    /** A user with no saved searches of their own exports (and round-trips) an empty array, not a missing key — nothing downstream needs to special-case its absence. */
    public function test_a_user_with_no_saved_searches_exports_an_empty_array(): void
    {
        Storage::fake('local');
        User::factory()->create(['email' => 'nobody-saved-anything@example.com']);

        $backup = app(BackupService::class)->create();
        $manifest = $this->readManifest($backup->filename);

        $exportedUser = collect($manifest['users'])->firstWhere('email', 'nobody-saved-anything@example.com');
        $this->assertSame([], $exportedUser['saved_searches']);
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
