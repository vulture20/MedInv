<?php

namespace App\Domain\ExportImport;

use App\Domain\Libraries\DuplicateEanException;
use App\Domain\Libraries\MediaItemService;
use App\Domain\Metadata\CoverDownloadService;
use App\Models\Library;
use App\Models\LibraryShare;
use App\Models\MetadataPlugin;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Instance-to-instance export/import (briefing 9.1) and the shared building
 * block for backup creation/restoration (9.2/9.3) — a backup is simply an
 * export of every library, zipped with a manifest, so BackupService reuses
 * exportLibraries()/importLibraries() rather than duplicating this format.
 *
 * The cover-archiving helpers below (addCoverFilesToZip()/
 * restoreCoverFilesFromZip()) live here rather than in BackupService because
 * BackupController::export()/import() (GitHub issue #26 follow-up) need
 * exactly the same behavior for an ordinary library export/import as
 * BackupService already had for a full backup: without them, a library
 * export/import silently lost every cover image, since cover_path only
 * resolves relative to the exporting instance's own `local` disk (see
 * CoverDownloadService). BackupService now calls these too instead of
 * keeping its own copy.
 */
class ExportImportService
{
    private const DISK = 'local';

    /** Must match CoverDownloadService::DIR — the prefix every item's cover_path is stored under on the `local` disk. */
    public const COVERS_DIR = 'covers';

    public function __construct(
        private readonly MediaItemService $mediaItemService,
        private readonly CoverDownloadService $coverDownloadService,
    ) {}

    /**
     * @param  int[]|null  $libraryIds  Null exports all libraries ("alle", briefing 9.1).
     * @param  bool  $includeUsers  Whether to also embed system_settings (briefing 15.,
     *                              including secrets in plaintext — mail.password,
     *                              oidc.client_secret) under `system_settings`, every user
     *                              account (incl. hashed password) under `users`, and every
     *                              metadata_plugins row (incl. provider config such as an API
     *                              key, GitHub issue #29) under `metadata_plugins`.
     *                              Deliberately opt-in and off by default: an ordinary
     *                              admin-initiated multi-library export to share with another
     *                              instance (briefing 9.1) has no reason to leak any of these
     *                              — a plugin's stored API key or a mail/OIDC secret is
     *                              exactly as sensitive as an account's password hash. All
     *                              three used to be conditional on this flag except
     *                              system_settings, which was included unconditionally in
     *                              every export regardless — a real reported leak: a plain
     *                              library export downloaded to share with someone else
     *                              carried the SMTP password and OIDC client secret in
     *                              plaintext even though nothing about that export's UI
     *                              suggested system configuration was involved at all.
     *                              BackupService::create() is the one caller that passes
     *                              true — a backup is meant to be a full snapshot of this
     *                              instance, all three included.
     * @return array{format_version: int, exported_at: string, libraries: array, system_settings?: array, users?: array, metadata_plugins?: array}
     */
    public function exportLibraries(?array $libraryIds = null, bool $includeUsers = false): array
    {
        $query = Library::query()->with('shares');

        if ($libraryIds !== null) {
            $query->whereIn('id', $libraryIds);
        }

        $data = [
            'format_version' => 1,
            'exported_at' => now()->toIso8601String(),
            'libraries' => $query->get()->map(fn (Library $library) => [
                'name' => $library->name,
                'description' => $library->description,
                'media_type' => $library->media_type,
                'shares' => $library->shares->map(fn (LibraryShare $s) => [
                    'scope' => $s->scope,
                    'user_email' => $s->user?->email,
                    // GitHub issue #79 — read back by importLibraries()'s
                    // restoreShares() (GitHub issue #80) when $restoreShares
                    // is true; see that method's docblock.
                    'access_level' => $s->access_level,
                ])->all(),
                'items' => $library->mediaItems()->get()->map(
                    fn ($item) => $item->makeHidden(['id', 'library_id', 'created_at', 'updated_at'])->toArray()
                )->all(),
            ])->all(),
        ];

        if ($includeUsers) {
            // A full backup (briefing 9.2) needs the complete system configuration
            // to actually be a restore point, including settings an admin never
            // explicitly saved (see SystemSetting::allAsArray()'s docblock) —
            // applying it back onto the target instance is still separately opt-in
            // via importLibraries()'s $restoreSettings flag, but that's a decision
            // about whether to *apply* the data on import, not whether the file
            // should *contain* it at all; see this parameter's docblock above for
            // why containing it at all is exactly the leak an ordinary library
            // export must never repeat.
            $data['system_settings'] = SystemSetting::allAsArray();

            // No `id` (a restore assigns new ones, same as libraries above) and no
            // remember_token/API tokens — those are session-bound and shouldn't
            // travel between instances.
            $data['users'] = User::query()->get()->map(fn (User $user) => [
                'name' => $user->name,
                'email' => $user->email,
                'password' => $user->password,
                'level' => $user->level,
                'is_active' => $user->is_active,
                'is_protected' => $user->is_protected,
                'preferred_language' => $user->preferred_language,
                'preferred_template' => $user->preferred_template,
            ])->all();

            $data['metadata_plugins'] = MetadataPlugin::query()->get()->map(fn (MetadataPlugin $plugin) => [
                'provider_key' => $plugin->provider_key,
                'name' => $plugin->name,
                'media_type' => $plugin->media_type,
                'enabled' => $plugin->enabled,
                'priority' => $plugin->priority,
                'config' => $plugin->config,
            ])->all();
        }

        return $data;
    }

    /**
     * Adds every cover image referenced anywhere in an exportLibraries()
     * payload under its own cover_path (already relative to the `local`
     * disk, e.g. `covers/book/1234-AbCdEfGh.jpg`, see CoverDownloadService)
     * — using that same relative path as the zip entry name so
     * restoreCoverFilesFromZip() can write it straight back without any
     * translation table. Best-effort per file: a cover_path whose file is
     * already gone (e.g. deleted by hand outside the app), or whose
     * thumbnail was never generated (CoverDownloadService::
     * generateThumbnail() is itself best-effort — see its docblock), is
     * skipped rather than failing the whole export.
     *
     * Each cover's thumbnail (CoverDownloadService::thumbnailPath()) is
     * added alongside the full-size original — an export/backup that
     * carried covers but not their thumbnails restored fine, but every
     * restored item's library list view (which serves the thumbnail, not
     * the full image — MediaItemController::coverThumbnail()) silently fell
     * back to shipping the full-size cover to every row until the next
     * unrelated cover change happened to regenerate it, reported as
     * thumbnails "missing from the zip".
     */
    public function addCoverFilesToZip(ZipArchive $zip, array $data): void
    {
        $coverPaths = collect($data['libraries'] ?? [])
            ->flatMap(fn (array $library) => collect($library['items'] ?? [])->pluck('cover_path'))
            ->filter()
            ->unique();

        $allPaths = $coverPaths->flatMap(fn (string $coverPath) => [$coverPath, $this->coverDownloadService->thumbnailPath($coverPath)]);

        foreach ($allPaths as $path) {
            if (Storage::disk(self::DISK)->exists($path)) {
                $zip->addFile(Storage::disk(self::DISK)->path($path), $path);
            }
        }
    }

    /**
     * Writes every `covers/...` entry in the archive back onto the `local`
     * disk at its original relative path. Callers must do this *before*
     * importLibraries() (re-)creates the items that reference them, so a
     * cover is already in place by the time an item pointing at it exists
     * and MediaItemController::cover() doesn't 404 for it post-import.
     * Restores every cover present in the zip regardless of which items
     * conflict-resolution ends up actually (re-)creating — simpler and more
     * robust than correlating the two, at the cost of occasionally leaving
     * an unreferenced file behind for a library that was skipped, no worse
     * than any other orphaned-cover case.
     */
    public function restoreCoverFilesFromZip(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name === false || ! str_starts_with($name, self::COVERS_DIR.'/')) {
                continue;
            }

            $contents = $zip->getFromIndex($i);

            if ($contents !== false) {
                Storage::disk(self::DISK)->put($name, $contents);
            }
        }
    }

    /**
     * Imports an export produced by exportLibraries(), applying the
     * per-library conflict resolution chosen by the user for any library
     * name that already exists at the target (briefing 9.1 + 9.3):
     * rename | merge | overwrite | skip | cancel.
     *
     * @param  array<string, string>  $conflictResolutions  Keyed by library name; two sentinel
     *                                                      keys are recognized alongside real library names:
     *                                                      `__all__` => `cancel` aborts the whole restore/import
     *                                                      before anything is written, and `__default__` sets the
     *                                                      resolution for any library not otherwise listed — used by
     *                                                      Console\Commands\RestoreBackupOnBoot (MEDINV_RESTOREBACKUP,
     *                                                      briefing 9.3), which restores unattended at container start
     *                                                      with no admin available to choose per-library, unlike
     *                                                      BackupController::restore()'s interactive admin-UI path.
     *                                                      Without `__default__`, an unlisted library is skipped.
     * @param  bool  $restoreSettings  Whether to also apply $data['system_settings'],
     *                                 $data['users'] and $data['metadata_plugins'] (present
     *                                 since exportLibraries() started including them, the
     *                                 latter two only when it was called with includeUsers:
     *                                 true — i.e. from a backup) onto this instance. Opt-in
     *                                 and defaulted to false: an ordinary library import
     *                                 shouldn't silently overwrite the target's
     *                                 mail/backup/security configuration, user accounts, or
     *                                 metadata-provider settings (incl. API keys). Users and
     *                                 metadata_plugins are both upserted (by email /
     *                                 provider_key respectively) rather than duplicated, since
     *                                 a restore is meant to reinstate the backed-up instance's
     *                                 state.
     * @param  bool  $restoreShares  Whether to also recreate each library's shares
     *                               (GitHub issue #80) — a separate opt-in from
     *                               $restoreSettings above, deliberately: unlike
     *                               system_settings/users/metadata_plugins, `shares` is
     *                               present in *every* export (exportLibraries() embeds it
     *                               unconditionally, not gated behind $includeUsers), so an
     *                               ordinary instance-to-instance library import can offer
     *                               this even though it can never offer $restoreSettings.
     *                               Applies only to a library that's newly created or
     *                               overwritten — a `merge` resolution leaves the existing
     *                               library's own share configuration alone, since merging
     *                               is presented as "add these items", not "also change who
     *                               can access this library". A scope=user share whose
     *                               user_email doesn't match any account on this instance is
     *                               silently skipped (counted in the returned
     *                               shares_skipped) rather than created with no target or
     *                               rejected outright — scope=guest/scope=all_users shares
     *                               have no such dependency and are never skipped.
     * @return array{created: string[], merged: string[], overwritten: string[], skipped: string[], settings_restored: bool, users_restored: string[], plugins_restored: string[], shares_restored: bool, shares_skipped: int}
     *
     * @throws InvalidImportFileException
     */
    public function importLibraries(array $data, User $importingAs, array $conflictResolutions = [], bool $restoreSettings = false, bool $restoreShares = false): array
    {
        // Validated up front, before anything (including the settings/users/
        // plugins restore below) is written — an admin-uploaded file could be
        // anything, and a malformed `libraries` entry discovered mid-way
        // through would otherwise leave settings/users partially restored
        // even though the libraries import itself then fails. A backup
        // produced by BackupService::create() is always well-formed by
        // construction, so this never rejects a genuine backup/export — only
        // a hand-edited or unrelated file.
        $this->assertValidPayload($data);

        $result = [
            'created' => [], 'merged' => [], 'overwritten' => [], 'skipped' => [],
            'settings_restored' => false, 'users_restored' => [], 'plugins_restored' => [],
            'shares_restored' => $restoreShares, 'shares_skipped' => 0,
        ];

        if (($conflictResolutions['__all__'] ?? null) === 'cancel') {
            return $result;
        }

        if ($restoreSettings && ! empty($data['system_settings'])) {
            foreach ($data['system_settings'] as $key => $value) {
                SystemSetting::set($key, $value);
            }
            $result['settings_restored'] = true;
        }

        if ($restoreSettings && ! empty($data['users'])) {
            foreach ($data['users'] as $userData) {
                User::query()->updateOrCreate(['email' => $userData['email']], [
                    'name' => $userData['name'],
                    'password' => $userData['password'],
                    'level' => $userData['level'],
                    'is_active' => $userData['is_active'],
                    'is_protected' => $userData['is_protected'] ?? false,
                    'preferred_language' => $userData['preferred_language'] ?? 'de',
                    'preferred_template' => $userData['preferred_template'] ?? 'light',
                ]);
                $result['users_restored'][] = $userData['email'];
            }
        }

        if ($restoreSettings && ! empty($data['metadata_plugins'])) {
            foreach ($data['metadata_plugins'] as $pluginData) {
                MetadataPlugin::query()->updateOrCreate(['provider_key' => $pluginData['provider_key']], [
                    'name' => $pluginData['name'],
                    'media_type' => $pluginData['media_type'],
                    'enabled' => $pluginData['enabled'],
                    'priority' => $pluginData['priority'] ?? 0,
                    'config' => $pluginData['config'] ?? null,
                ]);
                $result['plugins_restored'][] = $pluginData['provider_key'];
            }
        }

        DB::transaction(function () use ($data, $importingAs, $conflictResolutions, $restoreShares, &$result) {
            foreach ($data['libraries'] ?? [] as $libraryData) {
                $existing = Library::query()->where('name', $libraryData['name'])->first();

                if (! $existing) {
                    $this->createLibraryFromExport($libraryData, $importingAs, $restoreShares, $result);
                    $result['created'][] = $libraryData['name'];

                    continue;
                }

                $resolution = $conflictResolutions[$libraryData['name']] ?? $conflictResolutions['__default__'] ?? 'skip';

                match ($resolution) {
                    'rename' => $this->createLibraryFromExport(
                        [...$libraryData, 'name' => $libraryData['name'].' (imported '.now()->format('Y-m-d H:i').')'],
                        $importingAs, $restoreShares, $result
                    ) && $result['created'][] = $libraryData['name'],
                    'merge' => $this->mergeIntoLibrary($existing, $libraryData) && $result['merged'][] = $libraryData['name'],
                    'overwrite' => $this->overwriteLibrary($existing, $libraryData, $importingAs, $restoreShares, $result) && $result['overwritten'][] = $libraryData['name'],
                    default => $result['skipped'][] = $libraryData['name'],
                };
            }
        });

        return $result;
    }

    /**
     * Walks the same shape importLibraries() below actually consumes and
     * throws on the first concrete problem found — see
     * InvalidImportFileException's docblock for why this exists at all. Only
     * checks the columns that are NOT NULL without a default in the
     * `libraries`/media_* migrations (name, media_type, title, ean); every
     * other field is nullable, so a missing one there is legitimately just
     * "this export had no value for it", not a structural problem.
     */
    private function assertValidPayload(array $data): void
    {
        if (! array_key_exists('libraries', $data) || ! is_array($data['libraries'])) {
            throw new InvalidImportFileException('import_missing_libraries');
        }

        foreach ($data['libraries'] as $index => $libraryData) {
            if (! is_array($libraryData) || ! is_string($libraryData['name'] ?? null) || $libraryData['name'] === '') {
                throw new InvalidImportFileException('import_invalid_library', ['index' => $index, 'field' => 'name']);
            }

            if (! in_array($libraryData['media_type'] ?? null, ['book', 'cd', 'dvd_bluray'], true)) {
                throw new InvalidImportFileException('import_invalid_library', ['index' => $index, 'field' => 'media_type']);
            }

            if (isset($libraryData['items']) && ! is_array($libraryData['items'])) {
                throw new InvalidImportFileException('import_invalid_item', ['library' => $libraryData['name'], 'index' => 0]);
            }

            foreach ($libraryData['items'] ?? [] as $itemIndex => $item) {
                $title = $item['title'] ?? null;
                $ean = $item['ean'] ?? null;
                if (! is_array($item) || ! is_string($title) || $title === '' || ! is_string($ean) || $ean === '') {
                    throw new InvalidImportFileException('import_invalid_item', ['library' => $libraryData['name'], 'index' => $itemIndex]);
                }
            }
        }
    }

    /** @param  array{shares_skipped: int}  &$result  Updated in place — see restoreShares()'s docblock. */
    private function createLibraryFromExport(array $libraryData, User $owner, bool $restoreShares, array &$result): true
    {
        $library = Library::query()->create([
            'name' => $libraryData['name'],
            'description' => $libraryData['description'] ?? null,
            'media_type' => $libraryData['media_type'],
            'owner_id' => $owner->id,
        ]);

        $this->insertItems($library, $libraryData['items'] ?? []);

        if ($restoreShares) {
            $result['shares_skipped'] += $this->restoreShares($library, $libraryData['shares'] ?? []);
        }

        return true;
    }

    /**
     * GitHub issue #80: shares are deliberately left untouched here, unlike
     * createLibraryFromExport()/overwriteLibrary() — $library already exists
     * with its own share configuration, and `merge` is presented to the
     * admin as "add these items to the existing library", not "also change
     * who can access it".
     */
    private function mergeIntoLibrary(Library $library, array $libraryData): true
    {
        // Existing records win on EAN collision (5.1: no duplicate within a library).
        $this->insertItems($library, $libraryData['items'] ?? [], skipExistingEans: true);

        return true;
    }

    /** @param  array{shares_skipped: int}  &$result  Updated in place — see restoreShares()'s docblock. */
    private function overwriteLibrary(Library $library, array $libraryData, User $owner, bool $restoreShares, array &$result): true
    {
        $library->mediaItems()->delete();
        $library->update([
            'description' => $libraryData['description'] ?? null,
        ]);
        $this->insertItems($library, $libraryData['items'] ?? []);

        if ($restoreShares) {
            // Full replace, same as LibraryController::updateShares() and
            // consistent with `overwrite` already replacing this library's
            // items outright above rather than merging them.
            $library->shares()->delete();
            $result['shares_skipped'] += $this->restoreShares($library, $libraryData['shares'] ?? []);
        }

        return true;
    }

    /**
     * Recreates $library's shares from an exportLibraries() `shares` array
     * (GitHub issue #80) — mirrors LibraryController::updateShares()'s
     * insert logic. A scope=user share whose user_email doesn't match any
     * account on this instance is skipped rather than created with no
     * target (LibraryShare.user_id is nullable, but a "user" share with no
     * user would be visible/writable by nobody and unremovable through the
     * ordinary sharing UI, which only ever lists real accounts) or
     * rejected outright (an otherwise-valid restore shouldn't fail over one
     * stale email from a since-deleted account on the source instance).
     * access_level (#79) is preserved, except a malformed or missing value
     * defaults to 'read' — same fallback updateShares() itself uses for an
     * omitted access_level — and scope=guest can never end up 'write'
     * regardless of what the export claims (briefing 4.2: guests get no
     * write, full stop; LibraryAccessService::canWriteItems() would ignore
     * it anyway, but there's no reason to persist a nonsensical value).
     *
     * @return int How many shares were skipped for a missing target user.
     */
    private function restoreShares(Library $library, array $shares): int
    {
        $skipped = 0;

        foreach ($shares as $shareData) {
            $scope = $shareData['scope'] ?? null;
            if (! in_array($scope, ['guest', 'all_users', 'user'], true)) {
                continue; // malformed entry — same tolerance insertItems() has for a bad item
            }

            $userId = null;
            if ($scope === 'user') {
                $user = User::query()->where('email', $shareData['user_email'] ?? null)->first();
                if (! $user) {
                    $skipped++;

                    continue;
                }
                $userId = $user->id;
            }

            LibraryShare::query()->create([
                'library_id' => $library->id,
                'scope' => $scope,
                'user_id' => $userId,
                'access_level' => $scope !== 'guest' && ($shareData['access_level'] ?? 'read') === 'write' ? 'write' : 'read',
            ]);
        }

        return $skipped;
    }

    private function insertItems(Library $library, array $items, bool $skipExistingEans = false): void
    {
        foreach ($items as $item) {
            if ($skipExistingEans) {
                $modelClass = $this->mediaItemService->modelClassFor($library->media_type);
                if ($modelClass::query()->where('library_id', $library->id)->where('ean', $item['ean'])->exists()) {
                    continue;
                }
            }

            try {
                $this->mediaItemService->create($library, $item);
            } catch (DuplicateEanException) {
                // Already present — consistent with the strict-rejection rule in 5.1.
            }
        }
    }
}
