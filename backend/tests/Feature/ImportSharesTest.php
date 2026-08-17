<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\LibraryShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

/**
 * GitHub issue #80: exportLibraries() has always embedded a library's
 * shares (scope/user_email, and access_level since #79) in its output, but
 * importLibraries() never read that back — an import/backup-restore lost
 * every share silently. Covers the fix: `restore_shares`, a separate
 * opt-in from `restore_settings` (see ExportImportService::
 * importLibraries()'s docblock for why it has to be its own flag), and the
 * "skip a scope=user share whose user_email doesn't match any account
 * here" rule.
 */
class ImportSharesTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    private function upload(array $payload, array $extra = []): array
    {
        return $this->postJson('/api/admin/import', [
            'file' => $this->zipWithManifest(json_encode($payload)),
            ...$extra,
        ])->json();
    }

    private function zipWithManifest(string $manifestContent): UploadedFile
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'import-shares-test');
        $zip = new ZipArchive;
        $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', $manifestContent);
        $zip->close();

        return new UploadedFile($tmpZip, 'import.zip', 'application/zip', null, true);
    }

    private function payloadWithShares(array $shares): array
    {
        return [
            'libraries' => [[
                'name' => 'Imported Library',
                'description' => null,
                'media_type' => 'book',
                'shares' => $shares,
                'items' => [],
            ]],
        ];
    }

    public function test_shares_are_not_restored_without_the_opt_in_flag(): void
    {
        $this->actingAsAdmin();
        $this->upload($this->payloadWithShares([['scope' => 'all_users']]));

        $library = Library::query()->where('name', 'Imported Library')->firstOrFail();
        $this->assertSame(0, LibraryShare::query()->where('library_id', $library->id)->count());
    }

    public function test_all_users_and_guest_shares_are_restored_with_the_opt_in_flag(): void
    {
        $this->actingAsAdmin();
        $result = $this->upload(
            $this->payloadWithShares([['scope' => 'all_users'], ['scope' => 'guest']]),
            ['restore_shares' => true],
        );

        $library = Library::query()->where('name', 'Imported Library')->firstOrFail();
        $this->assertDatabaseHas((new LibraryShare)->getTable(), ['library_id' => $library->id, 'scope' => 'all_users']);
        $this->assertDatabaseHas((new LibraryShare)->getTable(), ['library_id' => $library->id, 'scope' => 'guest']);
        $this->assertTrue($result['shares_restored']);
        $this->assertSame(0, $result['shares_skipped']);
    }

    public function test_a_user_scope_share_is_restored_when_the_email_matches_an_existing_account(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create(['level' => 'user', 'email' => 'bob@example.com', 'is_active' => true]);

        $result = $this->upload(
            $this->payloadWithShares([['scope' => 'user', 'user_email' => 'bob@example.com']]),
            ['restore_shares' => true],
        );

        $library = Library::query()->where('name', 'Imported Library')->firstOrFail();
        $this->assertDatabaseHas((new LibraryShare)->getTable(), ['library_id' => $library->id, 'scope' => 'user', 'user_id' => $target->id]);
        $this->assertSame(0, $result['shares_skipped']);
    }

    /** The core rule from the issue: a share whose target no longer exists must be skipped, not created with no target and not fail the whole import. */
    public function test_a_user_scope_share_is_skipped_when_no_account_has_that_email(): void
    {
        $this->actingAsAdmin();

        $result = $this->upload(
            $this->payloadWithShares([['scope' => 'user', 'user_email' => 'nobody@example.com']]),
            ['restore_shares' => true],
        );

        $library = Library::query()->where('name', 'Imported Library')->firstOrFail();
        $this->assertSame(0, LibraryShare::query()->where('library_id', $library->id)->count());
        $this->assertSame(1, $result['shares_skipped']);
    }

    /** briefing 4.2: a guest never gets write access, regardless of what an (untrusted, admin-uploaded) import file claims. */
    public function test_a_guest_share_can_never_be_restored_as_write_even_if_the_file_claims_so(): void
    {
        $this->actingAsAdmin();

        $this->upload(
            $this->payloadWithShares([['scope' => 'guest', 'access_level' => 'write']]),
            ['restore_shares' => true],
        );

        $library = Library::query()->where('name', 'Imported Library')->firstOrFail();
        $this->assertDatabaseHas((new LibraryShare)->getTable(), ['library_id' => $library->id, 'scope' => 'guest', 'access_level' => 'read']);
    }

    /** GitHub issue #79's access_level must survive the round trip for a scope that's allowed to carry it. */
    public function test_a_write_access_level_is_preserved_for_a_user_scope_share(): void
    {
        $this->actingAsAdmin();
        User::factory()->create(['level' => 'user', 'email' => 'writer@example.com', 'is_active' => true]);

        $this->upload(
            $this->payloadWithShares([['scope' => 'user', 'user_email' => 'writer@example.com', 'access_level' => 'write']]),
            ['restore_shares' => true],
        );

        $library = Library::query()->where('name', 'Imported Library')->firstOrFail();
        $this->assertDatabaseHas((new LibraryShare)->getTable(), ['library_id' => $library->id, 'scope' => 'user', 'access_level' => 'write']);
    }

    /** `overwrite` fully replaces an existing library's shares too, same "full replace" semantics LibraryController::updateShares() and the item overwrite already have. */
    public function test_overwrite_resolution_replaces_existing_shares(): void
    {
        $admin = $this->actingAsAdmin();
        $existing = Library::query()->create(['name' => 'Imported Library', 'media_type' => 'book', 'owner_id' => $admin->id]);
        LibraryShare::query()->create(['library_id' => $existing->id, 'scope' => 'guest']);

        $result = $this->upload(
            $this->payloadWithShares([['scope' => 'all_users']]),
            ['restore_shares' => true, 'conflict_resolutions' => ['Imported Library' => 'overwrite']],
        );

        $this->assertSame(['Imported Library'], $result['overwritten']);
        $table = (new LibraryShare)->getTable();
        $this->assertDatabaseMissing($table, ['library_id' => $existing->id, 'scope' => 'guest']);
        $this->assertDatabaseHas($table, ['library_id' => $existing->id, 'scope' => 'all_users']);
    }

    /** `merge` deliberately leaves an existing library's own share configuration untouched — see ExportImportService::mergeIntoLibrary()'s docblock. */
    public function test_merge_resolution_leaves_existing_shares_untouched(): void
    {
        $admin = $this->actingAsAdmin();
        $existing = Library::query()->create(['name' => 'Imported Library', 'media_type' => 'book', 'owner_id' => $admin->id]);
        LibraryShare::query()->create(['library_id' => $existing->id, 'scope' => 'guest']);

        $result = $this->upload(
            $this->payloadWithShares([['scope' => 'all_users']]),
            ['restore_shares' => true, 'conflict_resolutions' => ['Imported Library' => 'merge']],
        );

        $this->assertSame(['Imported Library'], $result['merged']);
        $table = (new LibraryShare)->getTable();
        $this->assertDatabaseHas($table, ['library_id' => $existing->id, 'scope' => 'guest']);
        $this->assertDatabaseMissing($table, ['library_id' => $existing->id, 'scope' => 'all_users']);
    }

    /** A malformed scope in an admin-uploaded file is tolerated the same way insertItems() tolerates a bad item — skipped, not a fatal error for the whole import. */
    public function test_a_share_with_an_unrecognized_scope_is_silently_ignored(): void
    {
        $this->actingAsAdmin();

        $this->upload(
            $this->payloadWithShares([['scope' => 'everyone']]),
            ['restore_shares' => true],
        );

        $library = Library::query()->where('name', 'Imported Library')->firstOrFail();
        $this->assertSame(0, LibraryShare::query()->where('library_id', $library->id)->count());
    }
}
