<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupService;
use App\Domain\ExportImport\ExportImportService;
use App\Models\Library;
use App\Models\MediaBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * GitHub issue #153 (explicit user request): reverses part of #148's own
 * fix specifically for a real backup — `captured_by_user_id` (GitHub issue
 * #74) should still be preserved there, just not as the raw, instance-local
 * ID #148 rightly stripped, but resolved by email (`captured_by_email`) the
 * same "look up a real account, don't trust a foreign ID" way
 * `owner_email`/`shares[].user_email` already are. Its *presence in the
 * export* is gated behind `$includeUsers` (a real backup, never an
 * ordinary library export) — but, unlike an earlier version of this fix,
 * resolving it back on *import* is unconditional, the same as
 * `owner_email`/`shares[].user_email` themselves, not additionally gated
 * behind `$restoreSettings` too. That extra gate was itself a bug, reported
 * by the user: it meant restoring a backup without separately opting into
 * "restore settings" (the common case for restoring items/shares without
 * wanting to overwrite the current instance's own accounts) silently
 * discarded a perfectly resolvable `captured_by_email`.
 */
class CapturedByBackupTest extends TestCase
{
    use RefreshDatabase;

    private function library(int $ownerId): Library
    {
        return Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $ownerId]);
    }

    public function test_a_real_backup_includes_captured_by_email(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $captor = User::factory()->create(['email' => 'captor@example.com']);
        $library = $this->library($owner->id);
        MediaBook::query()->create([
            'library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'captured_by_user_id' => $captor->id,
        ]);

        $export = app(ExportImportService::class)->exportLibraries(null, includeUsers: true);

        $this->assertSame('captor@example.com', $export['libraries'][0]['items'][0]['captured_by_email']);
        $this->assertArrayNotHasKey('captured_by_user_id', $export['libraries'][0]['items'][0]);
        // Reported by the user: Eloquent's toArray() auto-serializes any
        // *loaded* relation under its own key unless hidden too -- eager
        // loading capturedBy to read its ->email above used to leak a
        // second, redundant "captured_by": {"id": ..., "email": ...} —
        // re-exposing the exact raw id captured_by_email exists to avoid.
        $this->assertArrayNotHasKey('captured_by', $export['libraries'][0]['items'][0]);
    }

    public function test_an_ordinary_export_includes_neither_field_at_all(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $captor = User::factory()->create(['email' => 'captor@example.com']);
        $library = $this->library($owner->id);
        MediaBook::query()->create([
            'library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'captured_by_user_id' => $captor->id,
        ]);

        $export = app(ExportImportService::class)->exportLibraries(null);

        $this->assertArrayNotHasKey('captured_by_email', $export['libraries'][0]['items'][0]);
        $this->assertArrayNotHasKey('captured_by_user_id', $export['libraries'][0]['items'][0]);
    }

    public function test_restoring_with_restore_settings_resolves_captured_by_email_to_the_matching_account(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);
        $captor = User::factory()->create(['email' => 'captor@example.com']);

        $data = [
            'libraries' => [[
                'name' => 'Novels', 'description' => null, 'media_type' => 'book', 'shares' => [],
                'items' => [['title' => 'Dune', 'ean' => '9780000000001', 'captured_by_email' => 'captor@example.com']],
            ]],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin, restoreSettings: true);

        $item = MediaBook::query()->where('ean', '9780000000001')->firstOrFail();
        $this->assertSame($captor->id, $item->captured_by_user_id);
    }

    /**
     * Regression test for the bug the user reported: resolving
     * captured_by_email must *not* require restoreSettings too — a real
     * backup restore that only opts into e.g. restoring shares (not
     * settings/user accounts) must still correctly attribute captured_by
     * against whichever accounts already exist on this instance.
     */
    public function test_restoring_without_restore_settings_still_resolves_captured_by_email(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);
        $captor = User::factory()->create(['email' => 'captor@example.com']);

        $data = [
            'libraries' => [[
                'name' => 'Novels', 'description' => null, 'media_type' => 'book', 'shares' => [],
                'items' => [['title' => 'Dune', 'ean' => '9780000000001', 'captured_by_email' => 'captor@example.com']],
            ]],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin);

        $item = MediaBook::query()->where('ean', '9780000000001')->firstOrFail();
        $this->assertSame($captor->id, $item->captured_by_user_id);
    }

    public function test_restoring_with_no_matching_account_leaves_it_null(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);

        $data = [
            'libraries' => [[
                'name' => 'Novels', 'description' => null, 'media_type' => 'book', 'shares' => [],
                'items' => [['title' => 'Dune', 'ean' => '9780000000001', 'captured_by_email' => 'long-gone@example.com']],
            ]],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin, restoreSettings: true);

        $item = MediaBook::query()->where('ean', '9780000000001')->firstOrFail();
        $this->assertNull($item->captured_by_user_id);
    }

    /**
     * GitHub issue #148's own defense-in-depth still holds even during a
     * real restore: a raw captured_by_user_id smuggled into a hand-crafted
     * file (rather than the email-based captured_by_email an actual export
     * produces) is never trusted, regardless of $restoreSettings — only an
     * email is ever resolved.
     */
    public function test_a_raw_captured_by_user_id_in_the_import_file_is_still_never_trusted(): void
    {
        $admin = User::factory()->create(['level' => 'admin']);
        $unrelatedUser = User::factory()->create(['email' => 'unrelated@example.com']);

        $data = [
            'libraries' => [[
                'name' => 'Novels', 'description' => null, 'media_type' => 'book', 'shares' => [],
                'items' => [['title' => 'Dune', 'ean' => '9780000000001', 'captured_by_user_id' => $unrelatedUser->id]],
            ]],
        ];

        app(ExportImportService::class)->importLibraries($data, $admin, restoreSettings: true);

        $item = MediaBook::query()->where('ean', '9780000000001')->firstOrFail();
        $this->assertNull($item->captured_by_user_id);
    }

    /**
     * The exact scenario the user's bug report traced back to:
     * BackupController::restore() (the interactive admin-UI path) defaults
     * `restore_settings` to false unless an admin explicitly opts in — a
     * perfectly ordinary way to restore a backup's libraries/shares
     * without also overwriting the current instance's own accounts, which
     * must not silently discard captured_by along the way.
     */
    public function test_a_full_backup_restore_preserves_captured_by_even_without_restoring_settings(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['level' => 'admin']);
        $captor = User::factory()->create(['email' => 'captor@example.com']);
        $library = $this->library($admin->id);
        MediaBook::query()->create([
            'library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'captured_by_user_id' => $captor->id,
        ]);

        $backup = app(BackupService::class)->create();

        MediaBook::query()->where('ean', '9780000000001')->delete();

        app(BackupService::class)->restore($backup, $admin, conflictResolutions: ['__default__' => 'overwrite'], restoreSettings: false, restoreShares: false);

        $restored = MediaBook::query()->where('ean', '9780000000001')->firstOrFail();
        $this->assertSame($captor->id, $restored->captured_by_user_id);
    }

    /** Full round trip through the real zip-based backup/restore path (BackupService), not just importLibraries() called directly. */
    public function test_a_full_backup_restore_preserves_captured_by(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['level' => 'admin']);
        $captor = User::factory()->create(['email' => 'captor@example.com']);
        $library = $this->library($admin->id);
        MediaBook::query()->create([
            'library_id' => $library->id, 'title' => 'Dune', 'ean' => '9780000000001', 'captured_by_user_id' => $captor->id,
        ]);

        $backup = app(BackupService::class)->create();
        $manifest = $this->readManifest($backup->filename);
        $this->assertSame('captor@example.com', $manifest['libraries'][0]['items'][0]['captured_by_email']);

        // Simulate restoring onto a fresh instance: the item is gone, only the backup remains.
        // '__default__' => 'overwrite' — same conflict-resolution sentinel
        // RestoreBackupOnBoot itself uses (briefing 9.3) — otherwise the
        // still-existing 'Novels' library would just be skipped rather
        // than actually restored.
        MediaBook::query()->where('ean', '9780000000001')->delete();

        app(BackupService::class)->restore($backup, $admin, conflictResolutions: ['__default__' => 'overwrite'], restoreSettings: true, restoreShares: false);

        $restored = MediaBook::query()->where('ean', '9780000000001')->firstOrFail();
        $this->assertSame($captor->id, $restored->captured_by_user_id);
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
