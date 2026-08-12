<?php

namespace App\Domain\ExportImport;

use App\Domain\Libraries\DuplicateEanException;
use App\Domain\Libraries\MediaItemService;
use App\Models\Library;
use App\Models\LibraryShare;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Instance-to-instance export/import (briefing 9.1) and the shared building
 * block for backup creation/restoration (9.2/9.3) — a backup is simply an
 * export of every library, zipped with a manifest, so BackupService reuses
 * exportLibraries()/importLibraries() rather than duplicating this format.
 */
class ExportImportService
{
    public function __construct(private readonly MediaItemService $mediaItemService) {}

    /**
     * @param  int[]|null  $libraryIds  Null exports all libraries ("alle", briefing 9.1).
     * @param  bool  $includeUsers  Whether to also embed every user account (incl. hashed
     *                              password) under `users`. Deliberately opt-in and off by
     *                              default: an ordinary admin-initiated multi-library export
     *                              to share with another instance (briefing 9.1) has no reason
     *                              to leak every account's password hash. BackupService::create()
     *                              is the one caller that passes true — a backup is meant to be
     *                              a full snapshot of this instance, users included.
     * @return array{format_version: int, exported_at: string, libraries: array, system_settings: array, users?: array}
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
            // Included unconditionally (BackupService::create() always exports "alle" and
            // reuses this method) so a backup carries the full system configuration
            // (mail/backup/security settings, briefing 15.) alongside the library data —
            // restoring them is opt-in per importLibraries()'s $restoreSettings flag,
            // since the settings of the *target* instance shouldn't change on every
            // ordinary library import.
            'system_settings' => SystemSetting::allAsArray(),
            'libraries' => $query->get()->map(fn (Library $library) => [
                'name' => $library->name,
                'description' => $library->description,
                'media_type' => $library->media_type,
                'shares' => $library->shares->map(fn (LibraryShare $s) => [
                    'scope' => $s->scope,
                    'user_email' => $s->user?->email,
                ])->all(),
                'items' => $library->mediaItems()->get()->map(
                    fn ($item) => $item->makeHidden(['id', 'library_id', 'created_at', 'updated_at'])->toArray()
                )->all(),
            ])->all(),
        ];

        if ($includeUsers) {
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
        }

        return $data;
    }

    /**
     * Imports an export produced by exportLibraries(), applying the
     * per-library conflict resolution chosen by the user for any library
     * name that already exists at the target (briefing 9.1 + 9.3):
     * rename | merge | overwrite | skip | cancel.
     *
     * @param  array<string, string>  $conflictResolutions  Keyed by library name.
     * @param  bool  $restoreSettings  Whether to also apply $data['system_settings'] and
     *                                 $data['users'] (present since exportLibraries() started
     *                                 including them, the latter only when it was called with
     *                                 includeUsers: true — i.e. from a backup) onto this
     *                                 instance. Opt-in and defaulted to false: an ordinary
     *                                 library import shouldn't silently overwrite the target's
     *                                 mail/backup/security configuration or its user accounts.
     *                                 Users are upserted by email — an existing account is
     *                                 updated (attributes + password hash) rather than
     *                                 duplicated, since a restore is meant to reinstate the
     *                                 backed-up instance's state.
     * @return array{created: string[], merged: string[], overwritten: string[], skipped: string[], settings_restored: bool, users_restored: string[]}
     */
    public function importLibraries(array $data, User $importingAs, array $conflictResolutions = [], bool $restoreSettings = false): array
    {
        $result = ['created' => [], 'merged' => [], 'overwritten' => [], 'skipped' => [], 'settings_restored' => false, 'users_restored' => []];

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

        DB::transaction(function () use ($data, $importingAs, $conflictResolutions, &$result) {
            foreach ($data['libraries'] ?? [] as $libraryData) {
                $existing = Library::query()->where('name', $libraryData['name'])->first();

                if (! $existing) {
                    $this->createLibraryFromExport($libraryData, $importingAs);
                    $result['created'][] = $libraryData['name'];

                    continue;
                }

                $resolution = $conflictResolutions[$libraryData['name']] ?? 'skip';

                match ($resolution) {
                    'rename' => $this->createLibraryFromExport(
                        [...$libraryData, 'name' => $libraryData['name'].' (imported '.now()->format('Y-m-d H:i').')'],
                        $importingAs
                    ) && $result['created'][] = $libraryData['name'],
                    'merge' => $this->mergeIntoLibrary($existing, $libraryData) && $result['merged'][] = $libraryData['name'],
                    'overwrite' => $this->overwriteLibrary($existing, $libraryData, $importingAs) && $result['overwritten'][] = $libraryData['name'],
                    default => $result['skipped'][] = $libraryData['name'],
                };
            }
        });

        return $result;
    }

    private function createLibraryFromExport(array $libraryData, User $owner): true
    {
        $library = Library::query()->create([
            'name' => $libraryData['name'],
            'description' => $libraryData['description'] ?? null,
            'media_type' => $libraryData['media_type'],
            'owner_id' => $owner->id,
        ]);

        $this->insertItems($library, $libraryData['items'] ?? []);

        return true;
    }

    private function mergeIntoLibrary(Library $library, array $libraryData): true
    {
        // Existing records win on EAN collision (5.1: no duplicate within a library).
        $this->insertItems($library, $libraryData['items'] ?? [], skipExistingEans: true);

        return true;
    }

    private function overwriteLibrary(Library $library, array $libraryData, User $owner): true
    {
        $library->mediaItems()->delete();
        $library->update([
            'description' => $libraryData['description'] ?? null,
        ]);
        $this->insertItems($library, $libraryData['items'] ?? []);

        return true;
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
