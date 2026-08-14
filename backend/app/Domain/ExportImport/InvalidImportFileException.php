<?php

namespace App\Domain\ExportImport;

use RuntimeException;

/**
 * Thrown by ExportImportService::importLibraries() when the supplied
 * payload doesn't actually look like a genuine exportLibraries() output.
 * Before this validation existed, a malformed or unrelated JSON file either
 * silently "succeeded" with an all-zero result (a missing `libraries` key
 * quietly defaulted to an empty array, so the admin saw "0 created, 0
 * overwritten, ..." with no indication the file was actually unusable) or
 * crashed mid-transaction with a raw, unhelpful DB constraint violation
 * once a NOT NULL column (name/media_type/title/ean) turned out missing.
 *
 * $errorCode is a stable, machine-readable identifier — named to avoid
 * colliding with Exception's own built-in (non-readonly, integer) $code
 * property, which a plain `public readonly string $code` promoted parameter
 * here would otherwise fatal on redeclaring. ExportImportController maps it
 * straight into the `{error_code, context}` response shape (see CLAUDE.md's
 * "API errors carry a machine-readable error_code" convention), with a
 * matching `errors.<code>` i18n key on the frontend. $context carries
 * whatever detail helps pinpoint the offending entry (a library index, an
 * item index, the missing field name, ...) so the admin gets a specific,
 * actionable message instead of a generic fallback.
 */
class InvalidImportFileException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly array $context = [])
    {
        parent::__construct("Invalid import file ({$errorCode}).");
    }
}
