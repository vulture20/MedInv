<?php

namespace App\Domain\ExportImport;

use App\Domain\Libraries\DuplicateEanException;
use App\Domain\Libraries\MediaItemService;
use App\Models\Library;
use App\Models\LibraryShare;
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
     * @return array{format_version: int, exported_at: string, libraries: array}
     */
    public function exportLibraries(?array $libraryIds = null): array
    {
        $query = Library::query()->with('shares');

        if ($libraryIds !== null) {
            $query->whereIn('id', $libraryIds);
        }

        return [
            'format_version' => 1,
            'exported_at' => now()->toIso8601String(),
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
    }

    /**
     * Imports an export produced by exportLibraries(), applying the
     * per-library conflict resolution chosen by the user for any library
     * name that already exists at the target (briefing 9.1 + 9.3):
     * rename | merge | overwrite | skip | cancel.
     *
     * @param  array<string, string>  $conflictResolutions  Keyed by library name.
     * @return array{created: string[], merged: string[], overwritten: string[], skipped: string[]}
     */
    public function importLibraries(array $data, User $importingAs, array $conflictResolutions = []): array
    {
        $result = ['created' => [], 'merged' => [], 'overwritten' => [], 'skipped' => []];

        if (($conflictResolutions['__all__'] ?? null) === 'cancel') {
            return $result;
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
