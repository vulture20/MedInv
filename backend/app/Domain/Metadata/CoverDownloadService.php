<?php

namespace App\Domain\Metadata;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Cover image storage: downloading a chosen metadata candidate's cover
 * (briefing 8.3 step 5, GitHub issue #6), manually uploading one from the
 * media item detail dialog, and deleting one — plus generating the
 * thumbnail every stored cover gets alongside it, used by the library
 * item-list view instead of shipping the full-size image to every row.
 * Stored on the `local` disk (storage/app/private/covers/...), not the
 * conventional `public` disk: this project's single-port Docker deployment
 * only proxies /api and /sanctum to php-fpm (see CLAUDE.md's "Two apps,
 * one deployable image" section) — nginx never serves Laravel's
 * public/storage symlink at all — so covers are served back through
 * MediaItemController::cover()/coverThumbnail() instead of a direct
 * storage URL, same as how backups are downloaded through
 * BackupController::download() rather than a public link.
 *
 * The thumbnail's path is deliberately *derived* from `cover_path`
 * (thumbnailPath()) rather than stored as its own database column —
 * `cover_path` already fully determines where a thumbnail lives, so a
 * second column would just be a second, potentially-diverging source of
 * truth for the same fact. This is also why the two files always travel
 * together in store()/delete() rather than needing separate bookkeeping.
 */
class CoverDownloadService
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    private const DIR = 'covers';

    /** Longest side a thumbnail is scaled down to, preserving aspect ratio; never upscaled past the original. */
    private const THUMBNAIL_MAX_DIMENSION = 160;

    public function __construct(private readonly CurlImageFetcher $imageFetcher) {}

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

        // SSRF guard: `$url` reaches here straight from admin/user-facing
        // input (MetadataController::import()/reimport()'s `cover_url`,
        // itself sourced from a metadata provider's response or the
        // frontend's MetadataMergeReview picker) — nothing upstream
        // restricts it to a public host. Blocks the direct case (a literal
        // loopback/link-local/RFC1918 IP, e.g. the cloud metadata address
        // 169.254.169.254, or 127.0.0.1) before this server ever makes a
        // request to it. Deliberately checks only a *literal* IP host, not
        // one requiring DNS resolution — resolving every hostname here would
        // also make this method (and its test suite, which mocks the actual
        // request but exercises this exact code path) depend on real DNS
        // even for entirely fake test domains. This does not close a
        // DNS-rebinding attack (a hostname resolving to a public IP at
        // request-validation time but a private one at connect time) or a
        // redirect-based one (CurlImageFetcher::fetch() still follows
        // redirects without re-checking each hop's host) — both are known,
        // accepted residual gaps for this best-effort feature, not fixed here.
        if ($this->isDisallowedLiteralIpHost($url)) {
            Log::info('Cover download blocked: URL host is a private/reserved IP address.', ['url' => $url]);

            return null;
        }

        // CurlImageFetcher, not Http::get() — see that class's docblock for why
        // (a real, live-confirmed bug: Cloudflare-fronted image CDNs, e.g.
        // Discogs', block Laravel's Guzzle-based client but not raw curl).
        $body = $this->imageFetcher->fetch($url);

        return $body === null ? null : $this->store($body, $mediaType, $ean);
    }

    /**
     * Manual cover upload (media item detail dialog's "upload cover"
     * action). Laravel's `image`/`max` validation rules already ran at the
     * controller boundary before this is called — store()'s own
     * getimagesizefromstring() re-check below is defense in depth, not the
     * only check, same paranoid stance download() already takes toward a
     * remote server's claimed Content-Type.
     */
    public function uploadFromFile(UploadedFile $file, string $mediaType, string $ean): ?string
    {
        $contents = $file->get();

        return $contents === false ? null : $this->store($contents, $mediaType, $ean);
    }

    /**
     * Deletes a cover and its thumbnail from disk (media item detail
     * dialog's "remove cover" action, cover replacement, and media item
     * deletion — see MediaItemController::destroy()/uploadCover()/
     * deleteCover()). Tolerates an already-missing file/null path so it's
     * always safe to call, the same trade-off BackupService::delete() makes
     * for its own Storage::delete() call.
     */
    public function delete(?string $coverPath): void
    {
        if ($coverPath === null || ! $this->isManagedPath($coverPath)) {
            return;
        }

        Storage::disk('local')->delete([$coverPath, $this->thumbnailPath($coverPath)]);
    }

    /**
     * Defense in depth against `cover_path` ever again reaching here as
     * something other than a value this class itself generated (store()
     * below always returns a `covers/<media_type>/<random-filename>` path)
     * — e.g. a still-undiscovered mass-assignment gap letting a stored
     * `cover_path` point somewhere else on the `local` disk entirely (see
     * MetadataController::import()/reimport()'s own comment on the mass-
     * assignment fix this pairs with). Used by both delete() above and
     * MediaItemController::streamCover() before either ever touches the
     * disk with a path pulled from the database, so this restriction holds
     * regardless of how a bad cover_path got there in the first place.
     */
    public function isManagedPath(string $path): bool
    {
        return str_starts_with($path, self::DIR.'/');
    }

    /** Pure path derivation, no I/O — see this class's docblock for why there's no separate `thumbnail_path` column. */
    public function thumbnailPath(string $coverPath): string
    {
        return dirname($coverPath).'/thumb_'.basename($coverPath);
    }

    /** See download()'s SSRF-guard comment for what this does and does not cover. */
    private function isDisallowedLiteralIpHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host)) {
            return false;
        }

        // parse_url() returns a literal-IPv6 host's brackets as part of the
        // string (`http://[::1]/...` -> `[::1]`, confirmed live), unlike
        // every other part of the URL syntax it otherwise unwraps for you —
        // filter_var(..., FILTER_VALIDATE_IP) rejects the bracketed form
        // outright, so without stripping them first, an IPv6 loopback/link-
        // local literal would silently fall through as "not a recognized
        // IP" -> treated as a hostname -> never blocked.
        $host = trim($host, '[]');

        // filter_var() only recognizes a canonical dotted-quad/colon-hex IP
        // string, not a hostname — so this is a no-op (never blocks) for an
        // ordinary domain name, which is the overwhelmingly common case and
        // exactly what lets test fixtures use a non-resolvable fake domain
        // (e.g. https://covers.example.com/...) without this ever touching
        // DNS.
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            && filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Validates raw image bytes (regardless of source — a remote HTTP
     * response or an uploaded file), stores the original under a
     * randomized filename, and generates+stores its thumbnail alongside it.
     */
    private function store(string $body, string $mediaType, string $ean): ?string
    {
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return null;
        }

        // Validated by actually decoding the image header rather than trusting a
        // claimed Content-Type/extension — a misconfigured or malicious source
        // could claim anything there.
        $imageInfo = @getimagesizefromstring($body);

        if ($imageInfo === false) {
            return null;
        }

        $extension = image_type_to_extension($imageInfo[2], include_dot: false);
        $filename = sprintf('%s-%s.%s', preg_replace('/[^A-Za-z0-9]/', '', $ean) ?: 'cover', Str::random(8), $extension);
        $path = self::DIR."/{$mediaType}/{$filename}";

        Storage::disk('local')->put($path, $body);
        $this->generateThumbnail($body, $imageInfo, $this->thumbnailPath($path));

        return $path;
    }

    /**
     * Best-effort: a thumbnail that fails to generate (corrupt-but-valid-
     * enough-for-getimagesizefromstring image, GD out of memory on a huge
     * image, ...) must not fail the whole upload/download — the original
     * cover was already stored above, and MediaItemController::
     * coverThumbnail() falls back to serving the full image when no
     * thumbnail file exists.
     *
     * This tolerance is also what let a real, systemic bug (GitHub issue
     * #47) go unnoticed for a while rather than surfacing as an obvious
     * failure: the GD extension was missing from docker/Dockerfile
     * entirely, so `imagecreatefromstring()` below threw "Call to
     * undefined function" — an \Error, still a \Throwable — on every
     * single thumbnail attempt in every Docker deployment, caught right
     * here and merely logged at INFO. The app kept working (covers still
     * displayed, just always full-size), which is exactly why nobody
     * noticed thumbnails specifically had never once actually been
     * generated. Fixed in the Dockerfile itself now, not here — this
     * method's job is still to be tolerant of a genuinely bad/huge image,
     * not to detect a missing extension.
     */
    private function generateThumbnail(string $body, array $imageInfo, string $thumbnailPath): void
    {
        try {
            $source = @imagecreatefromstring($body);

            if ($source === false) {
                return;
            }

            [$width, $height, $type] = $imageInfo;
            $scale = min(1, self::THUMBNAIL_MAX_DIMENSION / max($width, $height));
            $thumbWidth = max(1, (int) round($width * $scale));
            $thumbHeight = max(1, (int) round($height * $scale));

            $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
            // Preserve transparency (PNG/GIF/WEBP) instead of it turning solid black —
            // imagecopyresampled() otherwise composites onto whatever the destination's
            // default fill is.
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefilledrectangle($thumb, 0, 0, $thumbWidth, $thumbHeight, $transparent);

            imagecopyresampled($thumb, $source, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);
            imagedestroy($source);

            $encoded = $this->encode($thumb, $type);
            imagedestroy($thumb);

            if ($encoded !== null) {
                Storage::disk('local')->put($thumbnailPath, $encoded);
            }
        } catch (\Throwable $e) {
            Log::info('Thumbnail generation failed.', ['error' => $e->getMessage()]);
        }
    }

    /** Encodes to the same format as the source image where GD supports it, JPEG otherwise. */
    private function encode(\GdImage $image, int $imageType): ?string
    {
        ob_start();

        $written = match ($imageType) {
            IMAGETYPE_PNG => imagepng($image, quality: 6),
            IMAGETYPE_GIF => imagegif($image),
            IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($image) : imagejpeg($image, quality: 82),
            default => imagejpeg($image, quality: 82),
        };

        $contents = ob_get_clean();

        return $written ? $contents : null;
    }
}
