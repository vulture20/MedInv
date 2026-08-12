<?php

namespace App\Domain\Metadata;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Downloads a chosen candidate's cover image (briefing 8.3 step 5) and
 * stores it locally instead of just remembering the external URL — the
 * whole point being independence from the source provider staying up and
 * that URL staying valid (see GitHub issue #6). Stored on the `local` disk
 * (storage/app/private/covers/...), not the conventional `public` disk:
 * this project's single-port Docker deployment only proxies /api and
 * /sanctum to php-fpm (see CLAUDE.md's "Two apps, one deployable image"
 * section) — nginx never serves Laravel's public/storage symlink at all —
 * so covers are served back through MediaItemController::cover() instead
 * of a direct storage URL, same as how backups are downloaded through
 * BackupController::download() rather than a public link.
 */
class CoverDownloadService
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    private const DIR = 'covers';

    /**
     * @return string|null The relative path to store as the item's `cover_path`,
     *                     or null if the download/validation failed — a dead or
     *                     slow provider image is best-effort and must not fail
     *                     the whole import (the item itself was already created).
     */
    public function download(string $url, string $mediaType, string $ean): ?string
    {
        if (! preg_match('#^https?://#i', $url)) {
            return null;
        }

        try {
            $response = Http::timeout(10)->get($url);
        } catch (\Throwable $e) {
            Log::info('Cover download failed.', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return null;
        }

        // Validated by actually decoding the image header rather than trusting the
        // response's Content-Type — a misconfigured or malicious server could claim
        // anything there.
        $imageInfo = @getimagesizefromstring($body);

        if ($imageInfo === false) {
            return null;
        }

        $extension = image_type_to_extension($imageInfo[2], include_dot: false);
        $filename = sprintf('%s-%s.%s', preg_replace('/[^A-Za-z0-9]/', '', $ean) ?: 'cover', Str::random(8), $extension);
        $path = self::DIR."/{$mediaType}/{$filename}";

        Storage::disk('local')->put($path, $body);

        return $path;
    }
}
