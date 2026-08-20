<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupService;
use App\Domain\ExportImport\ExportImportService;
use App\Models\Library;
use App\Models\LibraryShare;
use App\Models\MediaBook;
use App\Models\MetadataPlugin;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GitHub issue #154, reported by the user: a restore/import used to
 * silently set `created_at`/`updated_at` to the current time for every
 * entity it (re-)creates — a media item's own `created_at` (shown as
 * "Hinzugefügt" in the Recent Additions report) always showed the restore
 * time instead of when it was actually originally added. Neither timestamp
 * is in any model's `#[Fillable(...)]` list (deliberately, so an *ordinary*
 * capture/creation path can never be made to spoof either one), and
 * exportLibraries() never included them either — see
 * ExportImportService::applyHistoricalTimestamps()'s own docblock for the
 * fix shared across every entity below.
 */
class RestoreTimestampsTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $historicalDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->historicalDate = Carbon::parse('2024-03-15 10:00:00');
    }

    public function test_media_item_created_at_and_updated_at_survive_a_round_trip(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $item->forceFill(['created_at' => $this->historicalDate, 'updated_at' => $this->historicalDate])->saveQuietly();

        // Items go through $item->toArray() (Eloquent's own default date
        // serialization, "Y-m-d\TH:i:s.u\Z") rather than the explicit
        // ->toIso8601String() call the manually-built library/user/share/
        // etc. arrays below use — both are valid ISO 8601 and Carbon::parse()
        // round-trips either fine, so this only asserts it *parses* back to
        // the same instant, not an exact string match.
        $export = app(ExportImportService::class)->exportLibraries(null);
        $this->assertTrue($this->historicalDate->eq(Carbon::parse($export['libraries'][0]['items'][0]['created_at'])));

        $admin = User::factory()->create(['level' => 'admin']);
        $export['libraries'][0]['name'] = 'Novels (restored)';
        app(ExportImportService::class)->importLibraries($export, $admin);

        $restored = MediaBook::query()->where('ean', '9780000000001')->where('library_id', '!=', $library->id)->firstOrFail();
        $this->assertTrue($this->historicalDate->eq($restored->created_at));
        $this->assertTrue($this->historicalDate->eq($restored->updated_at));
    }

    public function test_library_created_at_and_updated_at_survive_a_round_trip(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        $library->forceFill(['created_at' => $this->historicalDate, 'updated_at' => $this->historicalDate])->saveQuietly();

        $export = app(ExportImportService::class)->exportLibraries(null);
        $this->assertSame($this->historicalDate->toIso8601String(), $export['libraries'][0]['created_at']);

        $admin = User::factory()->create(['level' => 'admin']);
        $export['libraries'][0]['name'] = 'Novels (restored)';
        app(ExportImportService::class)->importLibraries($export, $admin);

        $restored = Library::query()->where('name', 'Novels (restored)')->firstOrFail();
        $this->assertTrue($this->historicalDate->eq($restored->created_at));
        $this->assertTrue($this->historicalDate->eq($restored->updated_at));
    }

    public function test_a_newly_created_users_created_at_and_updated_at_survive_a_round_trip(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);

        $data = [
            'libraries' => [],
            'users' => [[
                'name' => 'Someone', 'email' => 'someone@example.com', 'password' => 'hashed', 'level' => 'user', 'is_active' => true,
                'created_at' => $this->historicalDate->toIso8601String(), 'updated_at' => $this->historicalDate->toIso8601String(),
            ]],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin, restoreSettings: true);

        $restored = User::query()->where('email', 'someone@example.com')->firstOrFail();
        $this->assertTrue($this->historicalDate->eq($restored->created_at));
        $this->assertTrue($this->historicalDate->eq($restored->updated_at));
    }

    /** An existing account's own created_at was already correct — restoring it again from the backup must not corrupt it (it should already match; this proves it's not silently bumped to "now" like an ordinary Eloquent update would). */
    public function test_an_existing_users_created_at_stays_correct_after_a_repeat_restore(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);
        $existing = User::factory()->create(['email' => 'someone@example.com']);
        $existing->forceFill(['created_at' => $this->historicalDate])->saveQuietly();

        $data = [
            'libraries' => [],
            'users' => [[
                'name' => 'Someone', 'email' => 'someone@example.com', 'password' => 'hashed', 'level' => 'user', 'is_active' => true,
                'created_at' => $this->historicalDate->toIso8601String(), 'updated_at' => $this->historicalDate->toIso8601String(),
            ]],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin, restoreSettings: true);

        $restored = $existing->fresh();
        $this->assertTrue($this->historicalDate->eq($restored->created_at));
        $this->assertTrue($this->historicalDate->eq($restored->updated_at));
    }

    public function test_a_library_shares_created_at_and_updated_at_survive_a_round_trip(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);
        $sharedWith = User::factory()->create(['email' => 'shared-with@example.com']);

        $data = [
            'libraries' => [[
                'name' => 'Novels', 'description' => null, 'media_type' => 'book',
                'shares' => [[
                    'scope' => 'user', 'user_email' => 'shared-with@example.com', 'access_level' => 'read',
                    'created_at' => $this->historicalDate->toIso8601String(), 'updated_at' => $this->historicalDate->toIso8601String(),
                ]],
                'items' => [],
            ]],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin, restoreShares: true);

        $library = Library::query()->where('name', 'Novels')->firstOrFail();
        $share = LibraryShare::query()->where('library_id', $library->id)->where('user_id', $sharedWith->id)->firstOrFail();
        $this->assertTrue($this->historicalDate->eq($share->created_at));
        $this->assertTrue($this->historicalDate->eq($share->updated_at));
    }

    public function test_a_saved_searchs_created_at_and_updated_at_survive_a_round_trip(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);

        $data = [
            'libraries' => [],
            'users' => [[
                'name' => 'Someone', 'email' => 'someone@example.com', 'password' => 'hashed', 'level' => 'user', 'is_active' => true,
                'saved_searches' => [[
                    'name' => 'My search', 'filters' => ['query' => 'foo'],
                    'created_at' => $this->historicalDate->toIso8601String(), 'updated_at' => $this->historicalDate->toIso8601String(),
                ]],
            ]],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin, restoreSettings: true);

        $savedSearch = SavedSearch::query()->where('name', 'My search')->firstOrFail();
        $this->assertTrue($this->historicalDate->eq($savedSearch->created_at));
        $this->assertTrue($this->historicalDate->eq($savedSearch->updated_at));
    }

    public function test_a_metadata_plugins_created_at_and_updated_at_survive_a_round_trip(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);

        $data = [
            'libraries' => [],
            'metadata_plugins' => [[
                'provider_key' => 'book.a_brand_new_provider', 'name' => 'New Provider', 'media_type' => 'book', 'enabled' => true,
                'created_at' => $this->historicalDate->toIso8601String(), 'updated_at' => $this->historicalDate->toIso8601String(),
            ]],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin, restoreSettings: true);

        $plugin = MetadataPlugin::query()->where('provider_key', 'book.a_brand_new_provider')->firstOrFail();
        $this->assertTrue($this->historicalDate->eq($plugin->created_at));
        $this->assertTrue($this->historicalDate->eq($plugin->updated_at));
    }

    /** An export from before GitHub issue #154 has no created_at/updated_at keys at all — must not error, simply keeps whatever Eloquent's own default already set. */
    public function test_import_does_not_fail_when_timestamps_are_absent_entirely(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);

        $data = [
            'libraries' => [[
                'name' => 'Novels', 'description' => null, 'media_type' => 'book', 'shares' => [],
                'items' => [['title' => 'Dune', 'ean' => '9780000000001']],
            ]],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin);

        $item = MediaBook::query()->where('ean', '9780000000001')->firstOrFail();
        $this->assertNotNull($item->created_at);
    }

    /** Full round trip through the real zip-based backup/restore path, not just importLibraries() called directly. */
    public function test_a_full_backup_restore_preserves_the_original_created_at(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['level' => 'admin']);
        $library = Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $admin->id]);
        $item = MediaBook::query()->create(['library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001']);
        $item->forceFill(['created_at' => $this->historicalDate])->saveQuietly();

        $backup = app(BackupService::class)->create();

        MediaBook::query()->where('ean', '9780000000001')->delete();

        app(BackupService::class)->restore($backup, $admin, conflictResolutions: ['__default__' => 'overwrite'], restoreSettings: true, restoreShares: false);

        $restored = MediaBook::query()->where('ean', '9780000000001')->firstOrFail();
        $this->assertTrue($this->historicalDate->eq($restored->created_at));
    }
}
