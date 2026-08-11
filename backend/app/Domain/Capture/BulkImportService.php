<?php

namespace App\Domain\Capture;

use App\Domain\Libraries\MediaItemService;
use App\Domain\Metadata\MetadataImportService;
use App\Models\Library;

/**
 * Shared processing for all three equally-supported bulk capture paths
 * (briefing 7.2): hardware barcode scanner and camera-based scanning both
 * submit one EAN at a time as if typed, so they reuse resolveOne(); the
 * text-file import (one EAN per line, against a chosen target library)
 * calls resolveOne() for each line via resolveMany().
 *
 * Every code goes through the same steps regardless of entry path (7.2):
 * duplicate check within the target library (5.1) first, then automatic
 * metadata lookup (8.3). Ambiguous matches are returned as candidates for
 * the user to pick from or reject entirely — this service never guesses.
 */
class BulkImportService
{
    public function __construct(
        private readonly MetadataImportService $metadataImportService,
        private readonly MediaItemService $mediaItemService,
    ) {}

    /** @return array{status: string, ean: string, candidates?: array} */
    public function resolveOne(Library $library, string $ean): array
    {
        if ($this->eanExistsInLibrary($library, $ean)) {
            return ['status' => 'duplicate', 'ean' => $ean];
        }

        $candidates = $this->metadataImportService->lookup($library, $ean);

        return [
            'status' => empty($candidates) ? 'no_match' : 'candidates',
            'ean' => $ean,
            'candidates' => $candidates,
        ];
    }

    /**
     * @param  string[]  $eans
     * @return array<int, array>
     */
    public function resolveMany(Library $library, array $eans): array
    {
        return array_map(fn (string $ean) => $this->resolveOne($library, trim($ean)), array_filter($eans, 'trim'));
    }

    /** Parses an uploaded text file into a list of EANs, one per line (briefing 7.2). */
    public function parseEanTextFile(string $contents): array
    {
        return collect(preg_split('/\r\n|\r|\n/', $contents))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    private function eanExistsInLibrary(Library $library, string $ean): bool
    {
        $modelClass = $this->mediaItemService->modelClassFor($library->media_type);

        return $modelClass::query()->where('library_id', $library->id)->where('ean', $ean)->exists();
    }
}
