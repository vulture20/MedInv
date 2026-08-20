<?php

namespace App\Domain\ExportImport;

use App\Domain\Libraries\DuplicateEanException;
use App\Domain\Libraries\MediaItemService;
use App\Domain\Metadata\CoverDownloadService;
use App\Models\Library;
use App\Models\LibraryShare;
use App\Models\MetadataPlugin;
use App\Models\SavedSearch;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
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
     *                              account (incl. hashed password, and each user's own saved
     *                              searches — GitHub issue #73's "nice to have", nested as
     *                              `saved_searches` under the owning user, GitHub issue #125)
     *                              under `users`, and every metadata_plugins row (incl.
     *                              provider config such as an API key, GitHub issue #29) under
     *                              `metadata_plugins`. Deliberately opt-in and off by default:
     *                              an ordinary admin-initiated multi-library export to share
     *                              with another instance (briefing 9.1) has no reason to leak
     *                              any of these — a plugin's stored API key or a mail/OIDC
     *                              secret is exactly as sensitive as an account's password
     *                              hash, and a saved search is meaningless orphaned data
     *                              without the user it belongs to (nesting it under that user
     *                              rather than a separate list correlated by email/id means it
     *                              can never even be exported without them). All three used to
     *                              be conditional on this flag except system_settings, which
     *                              was included unconditionally in every export regardless —
     *                              a real reported leak: a plain library export downloaded to
     *                              share with someone else carried the SMTP password and OIDC
     *                              client secret in plaintext even though nothing about that
     *                              export's UI suggested system configuration was involved at
     *                              all. BackupService::create() is the one caller that passes
     *                              true — a backup is meant to be a full snapshot of this
     *                              instance, all three included.
     * @return array{format_version: int, exported_at: string, libraries: array, system_settings?: array, users?: array, metadata_plugins?: array}
     */
    public function exportLibraries(?array $libraryIds = null, bool $includeUsers = false): array
    {
        $query = Library::query()->with(['shares', 'owner']);

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
                // GitHub issue #152: previously missing entirely — every
                // restored/imported library ended up owned by whoever
                // performed the restore/import instead of its original
                // owner, a real loss for a full backup/restore
                // (BackupService/MEDINV_RESTOREBACKUP), whose whole point
                // is reproducing the exact prior state. Read back by
                // createLibraryFromExport() below the same way
                // `shares[].user_email` already is — resolved by email
                // against an existing/just-restored account, falling back
                // to the importing user (not a hard failure) when no match
                // is found, the same tolerance restoreShares() already
                // shows for a stale share email. Deliberately not gated
                // behind $includeUsers, matching `shares[].user_email`'s
                // own precedent (see this method's docblock on that field)
                // rather than `system_settings`/`users`/`metadata_plugins`'s.
                'owner_email' => $library->owner?->email,
                // GitHub issue #152: also previously missing — silently
                // reset to its DB default (false) on every restore/import.
                // Currently inconsequential (nothing else in the app reads
                // this flag yet), but still real data loss.
                'is_sample_library' => $library->is_sample_library,
                // GitHub issue #154: previously missing (like every other
                // created_at/updated_at below) — see insertItems()'s own
                // docblock on applyHistoricalTimestamps() for the full
                // reasoning shared across every entity this file restores.
                'created_at' => $library->created_at?->toIso8601String(),
                'updated_at' => $library->updated_at?->toIso8601String(),
                'shares' => $library->shares->map(fn (LibraryShare $s) => [
                    'scope' => $s->scope,
                    'user_email' => $s->user?->email,
                    // GitHub issue #79 — read back by importLibraries()'s
                    // restoreShares() (GitHub issue #80) when $restoreShares
                    // is true; see that method's docblock.
                    'access_level' => $s->access_level,
                    // GitHub issue #154.
                    'created_at' => $s->created_at?->toIso8601String(),
                    'updated_at' => $s->updated_at?->toIso8601String(),
                ])->all(),
                // GitHub issue #148: the raw `captured_by_user_id` (GitHub
                // issue #74) is instance-local exactly like `id`/
                // `library_id` — a plain foreign key with no meaning on a
                // different instance's own `users` table, and unlike every
                // other personal-data field, wasn't already gated behind
                // $includeUsers. Always hidden here, never the raw ID
                // itself — trusting a foreign instance's own internal ID
                // straight into this instance's `users` table would (a)
                // leak it into an *ordinary* library export, and (b) risk
                // wrongly attributing an imported item's capture history
                // to whichever real, unrelated account happens to share
                // that ID here (most commonly ID 1, the seeded admin), or
                // fail an insert outright on a genuinely fresh instance
                // where no user has that ID at all (the column has a real
                // NOT NULL-adjacent foreign key).
                //
                // GitHub issue #153 (follow-up, explicit user request):
                // `captured_by_email` carries the same information the
                // unconditional `shares[].user_email`/`owner_email` above
                // already do — resolved by email against a real account,
                // not a raw cross-instance ID — but its *presence in the
                // export* is specifically gated behind $includeUsers (a
                // real backup) rather than being unconditional like those
                // two: "who captured this" is meaningfully private in a
                // way "this library is shared with/owned by" isn't, so an
                // ordinary library export (never $includeUsers) still
                // leaks nothing here at all, matching `system_settings`/
                // `users`/`metadata_plugins`'s own precedent instead. Once
                // present in an import file, though, it's resolved by
                // insertItems() below exactly as unconditionally as
                // `owner_email`/`shares[].user_email` already are — *not*
                // additionally gated behind $restoreSettings the way
                // `users`/`metadata_plugins` themselves are, since a plain
                // `where('email', ...)` lookup against whatever accounts
                // already exist on this instance carries the same, already-
                // accepted risk profile regardless of whether this same
                // operation also happens to be restoring settings/users —
                // an earlier version of this fix gated it behind
                // $restoreSettings too, which meant restoring a backup
                // without separately opting into "restore settings" (the
                // common case for restoring items/shares without wanting
                // to overwrite the current instance's own accounts) left
                // captured_by_user_id null even though the file genuinely
                // had a resolvable captured_by_email, reported by the user
                // as the field silently getting discarded on import.
                'items' => $library->mediaItems()->when(
                    $includeUsers,
                    fn ($query) => $query->with('capturedBy:id,email')
                )->get()->map(
                    fn ($item) => [
                        // Eloquent's toArray() auto-serializes any *loaded*
                        // relation as its own key (here: a nested
                        // "captured_by": {"id": ..., "email": ...} —
                        // re-leaking the exact raw internal id captured_by_email
                        // below exists specifically to avoid exposing) unless
                        // it's hidden too, same as any other attribute —
                        // makeHidden() takes the relation's own method name
                        // (`capturedBy`), not the snake_cased key it would
                        // render under.
                        // GitHub issue #154: created_at/updated_at are no
                        // longer hidden here — see insertItems()'s own
                        // docblock on applyHistoricalTimestamps().
                        ...$item->makeHidden(['id', 'library_id', 'captured_by_user_id', 'capturedBy'])->toArray(),
                        ...($includeUsers ? ['captured_by_email' => $item->capturedBy?->email] : []),
                    ]
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
            //
            // Each user's own saved searches (GitHub issue #73's "nice to
            // have", GitHub issue #125) nest right here as a `saved_searches`
            // child array — same shape `libraries[].shares` already uses for
            // "this collection belongs to the entity it's embedded in",
            // rather than a separate top-level list correlated by a foreign
            // key of some kind. No `user_email`/`user_id` needed on each
            // entry itself: which user it belongs to is exactly which
            // user object it's nested under.
            $data['users'] = User::query()->with('savedSearches')->get()->map(fn (User $user) => [
                'name' => $user->name,
                'email' => $user->email,
                'password' => $user->password,
                'level' => $user->level,
                'is_active' => $user->is_active,
                'is_protected' => $user->is_protected,
                'preferred_language' => $user->preferred_language,
                'preferred_template' => $user->preferred_template,
                // GitHub issue #154.
                'created_at' => $user->created_at?->toIso8601String(),
                'updated_at' => $user->updated_at?->toIso8601String(),
                'saved_searches' => $user->savedSearches->map(fn (SavedSearch $s) => [
                    'name' => $s->name,
                    'filters' => $s->filters,
                    // GitHub issue #154.
                    'created_at' => $s->created_at?->toIso8601String(),
                    'updated_at' => $s->updated_at?->toIso8601String(),
                ])->all(),
            ])->all();

            $data['metadata_plugins'] = MetadataPlugin::query()->get()->map(fn (MetadataPlugin $plugin) => [
                'provider_key' => $plugin->provider_key,
                'name' => $plugin->name,
                'media_type' => $plugin->media_type,
                'enabled' => $plugin->enabled,
                'priority' => $plugin->priority,
                'config' => $plugin->config,
                // GitHub issue #154.
                'created_at' => $plugin->created_at?->toIso8601String(),
                'updated_at' => $plugin->updated_at?->toIso8601String(),
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
     *
     * GitHub issue #147 (a follow-up to the #146 security review): `$name`
     * is an attacker-controllable entry name straight out of an uploaded
     * zip — Flysystem's local adapter already refuses to write outside its
     * root for a path-traversal name like `covers/../../../../etc/x`
     * (`League\Flysystem\PathTraversalDetected`, confirmed live), so this
     * was never an actual arbitrary-file-write vulnerability, but letting
     * that exception escape uncaught used to abort the *entire*
     * import/restore with a 500 instead of just skipping the one bad/
     * unwritable entry and completing the rest — the same "best effort,
     * logged, never bubble up" stance JpcScraping::jpcGet() already takes
     * toward unreliable external input.
     */
    public function restoreCoverFilesFromZip(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name === false || ! str_starts_with($name, self::COVERS_DIR.'/')) {
                continue;
            }

            $contents = $zip->getFromIndex($i);

            if ($contents === false) {
                continue;
            }

            try {
                Storage::disk(self::DISK)->put($name, $contents);
            } catch (FilesystemException $e) {
                Log::warning('Skipped a cover file entry while restoring from zip.', ['name' => $name, 'error' => $e->getMessage()]);
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
     *                                 $data['users'] (including each user's own
     *                                 `saved_searches`, GitHub issue #125) and
     *                                 $data['metadata_plugins'] (present since exportLibraries()
     *                                 started including them, the latter two only when it was
     *                                 called with includeUsers: true — i.e. from a backup) onto
     *                                 this instance. Opt-in and defaulted to false: an ordinary
     *                                 library import shouldn't silently overwrite the target's
     *                                 mail/backup/security configuration, user accounts,
     *                                 anyone's saved searches, or metadata-provider settings
     *                                 (incl. API keys). Users and metadata_plugins are both
     *                                 upserted (by email / provider_key respectively) rather
     *                                 than duplicated, since a restore is meant to reinstate the
     *                                 backed-up instance's state; each user's saved_searches are
     *                                 replaced instead (deleted, then recreated from the
     *                                 payload) — there's no natural unique key to upsert a saved
     *                                 search against (SavedSearchController::store() doesn't
     *                                 enforce unique names, even per user), and this still keeps
     *                                 a repeated restore (MEDINV_RESTOREBACKUP, briefing 9.3)
     *                                 from duplicating the same ones on every restart. Does *not*
     *                                 gate each item's `captured_by_email` (GitHub issue #153,
     *                                 present only when exportLibraries() was called with
     *                                 includeUsers: true) — that's resolved back onto
     *                                 `captured_by_user_id` unconditionally by insertItems(),
     *                                 the same way owner_email/shares[].user_email already are;
     *                                 see insertItems()'s own docblock for why tying it to this
     *                                 flag too was itself a bug.
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
     * @return array{created: string[], merged: string[], overwritten: string[], skipped: string[], settings_restored: bool, users_restored: string[], plugins_restored: string[], shares_restored: bool, shares_skipped: int, saved_searches_restored: int}
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
            'saved_searches_restored' => 0,
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
                $user = User::query()->updateOrCreate(['email' => $userData['email']], [
                    'name' => $userData['name'],
                    'password' => $userData['password'],
                    'level' => $userData['level'],
                    'is_active' => $userData['is_active'],
                    'is_protected' => $userData['is_protected'] ?? false,
                    'preferred_language' => $userData['preferred_language'] ?? 'de',
                    'preferred_template' => $userData['preferred_template'] ?? 'light',
                ]);
                // GitHub issue #154.
                $this->applyHistoricalTimestamps($user, $userData);
                $result['users_restored'][] = $userData['email'];

                // GitHub issue #125 — nested under this user (see
                // exportLibraries()'s own docblock for why), so restoring it
                // is just as directly scoped: no separate email lookup
                // needed, $user is already exactly the right one. Replaced
                // rather than upserted per entry — see this method's own
                // $restoreSettings docblock for why.
                if (! empty($userData['saved_searches'])) {
                    SavedSearch::query()->where('user_id', $user->id)->delete();

                    foreach ($userData['saved_searches'] as $searchData) {
                        $savedSearch = SavedSearch::query()->create([
                            'user_id' => $user->id,
                            'name' => $searchData['name'] ?? '',
                            'filters' => $searchData['filters'] ?? [],
                        ]);
                        // GitHub issue #154.
                        $this->applyHistoricalTimestamps($savedSearch, $searchData);
                        $result['saved_searches_restored']++;
                    }
                }
            }
        }

        if ($restoreSettings && ! empty($data['metadata_plugins'])) {
            foreach ($data['metadata_plugins'] as $pluginData) {
                $plugin = MetadataPlugin::query()->updateOrCreate(['provider_key' => $pluginData['provider_key']], [
                    'name' => $pluginData['name'],
                    'media_type' => $pluginData['media_type'],
                    'enabled' => $pluginData['enabled'],
                    'priority' => $pluginData['priority'] ?? 0,
                    'config' => $pluginData['config'] ?? null,
                ]);
                // GitHub issue #154.
                $this->applyHistoricalTimestamps($plugin, $pluginData);
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
            // GitHub issue #152.
            'owner_id' => $this->resolveLibraryOwner($libraryData, $owner)->id,
            'is_sample_library' => $libraryData['is_sample_library'] ?? false,
        ]);
        // GitHub issue #154.
        $this->applyHistoricalTimestamps($library, $libraryData);

        $this->insertItems($library, $libraryData['items'] ?? []);

        if ($restoreShares) {
            $result['shares_skipped'] += $this->restoreShares($library, $libraryData['shares'] ?? []);
        }

        return true;
    }

    /**
     * GitHub issue #152: prefers the library's *original* owner (matched by
     * `owner_email`, the same "resolve a user reference by email, not a
     * raw cross-instance ID" approach `restoreShares()` already uses for
     * `shares[].user_email`) over `$owner` (the account actually performing
     * this import/restore) — a full backup/restore's whole point is
     * reproducing the exact prior state, which previously didn't extend to
     * who owned each library at all. Falls back to `$owner` when there's no
     * `owner_email` at all (an older export predating this field) or it
     * doesn't match any account on this instance (the original owner was
     * never included in this particular restore, e.g. a plain library
     * export without `$includeUsers`/a partial restore) — an otherwise-
     * valid restore shouldn't fail over one unresolvable email, the same
     * tolerance restoreShares() itself already shows for a stale share.
     */
    private function resolveLibraryOwner(array $libraryData, User $owner): User
    {
        $email = $libraryData['owner_email'] ?? null;

        if (! is_string($email) || $email === '') {
            return $owner;
        }

        return User::query()->where('email', $email)->first() ?? $owner;
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

            $share = LibraryShare::query()->create([
                'library_id' => $library->id,
                'scope' => $scope,
                'user_id' => $userId,
                'access_level' => $scope !== 'guest' && ($shareData['access_level'] ?? 'read') === 'write' ? 'write' : 'read',
            ]);
            // GitHub issue #154.
            $this->applyHistoricalTimestamps($share, $shareData);
        }

        return $skipped;
    }

    /**
     * GitHub issue #154, reported by the user: a restore/import used to
     * silently set `created_at`/`updated_at` to the current time for every
     * entity it (re-)creates — a media item's own `created_at` (shown as
     * "Hinzugefügt" in the Recent Additions report) always showed the
     * restore time instead of when it was actually originally added.
     * Root cause was the same across every entity this class restores:
     * neither timestamp is in any model's `#[Fillable(...)]` list (a
     * caller going through an *ordinary* capture/creation path must never
     * be able to spoof either one — that's deliberate and stays exactly
     * as it was), and exportLibraries() never included them either, so
     * they simply never reached create()/updateOrCreate() at all, and
     * Eloquent's own "set both to now on insert" default silently won
     * every time. Called explicitly, right after create()/updateOrCreate(),
     * only from this class's own restore/import call sites —
     * `forceFill()` bypasses mass assignment entirely (the column staying
     * out of `$fillable` is what keeps every *other* caller of
     * MediaItemService::create()/etc. safe), and `saveQuietly()` (not
     * `save()`) avoids firing model events for what is, semantically,
     * still "the original creation" being reproduced, not a genuine
     * update something should react to. A `$data` with neither key
     * present (an ordinary, non-timestamp-carrying payload, or an export
     * from before this field existed) is a no-op — the record simply
     * keeps whatever Eloquent's own default already set.
     */
    private function applyHistoricalTimestamps(Model $model, array $data): void
    {
        $timestamps = array_filter([
            'created_at' => $data['created_at'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
        ], fn (?string $value) => $value !== null);

        if ($timestamps === []) {
            return;
        }

        $model->forceFill($timestamps)->saveQuietly();
    }

    /**
     * GitHub issue #148: the raw `captured_by_user_id` a hand-crafted (not
     * necessarily this app's own export) import file might carry is always
     * stripped and never trusted directly — the same defense-in-depth
     * reasoning MediaItemController::store()/MetadataController::import()
     * already apply against a client-supplied value on the ordinary
     * capture paths (CaptureAttributionTest::
     * test_store_ignores_an_attacker_supplied_capture_method()): a value
     * that happens to match a real account on *this* instance would
     * otherwise falsely attribute that account with having captured an
     * item it never touched.
     *
     * GitHub issue #153 (follow-up, explicit user request): `captured_by_email`
     * (exportLibraries()'s own field — present only in a real backup, see
     * that method's own docblock) is resolved back onto
     * `captured_by_user_id` by email here — the same "look up a real
     * account, don't trust a foreign instance's raw ID" approach
     * `resolveLibraryOwner()`/`restoreShares()` already take for
     * `owner_email`/`shares[].user_email`, and, like both of those,
     * resolved unconditionally rather than gated behind
     * `$restoreSettings` — see exportLibraries()'s own docblock for why an
     * earlier version of this fix gating it that way was itself a bug
     * (reported by the user as the field silently getting discarded on a
     * backup restore that didn't separately opt into "restore settings").
     * An email matching no account (or absent entirely, e.g. an ordinary
     * import, or an export from before this field existed) simply leaves
     * it null — the same already-anticipated "unknown" case a pre-#74
     * item's own null `captured_by_user_id` gets (see ReportsService::
     * userActivityFor()'s own docblock).
     */
    private function insertItems(Library $library, array $items, bool $skipExistingEans = false): void
    {
        foreach ($items as $item) {
            $capturedByEmail = $item['captured_by_email'] ?? null;
            $timestamps = ['created_at' => $item['created_at'] ?? null, 'updated_at' => $item['updated_at'] ?? null];
            unset($item['captured_by_user_id'], $item['captured_by_email'], $item['created_at'], $item['updated_at']);
            $item['captured_by_user_id'] = (is_string($capturedByEmail) && $capturedByEmail !== '')
                ? User::query()->where('email', $capturedByEmail)->value('id')
                : null;

            if ($skipExistingEans) {
                $modelClass = $this->mediaItemService->modelClassFor($library->media_type);
                if ($modelClass::query()->where('library_id', $library->id)->where('ean', $item['ean'])->exists()) {
                    continue;
                }
            }

            try {
                $created = $this->mediaItemService->create($library, $item);
                $this->applyHistoricalTimestamps($created, $timestamps);
            } catch (DuplicateEanException) {
                // Already present — consistent with the strict-rejection rule in 5.1.
            }
        }
    }
}
