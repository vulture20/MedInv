<?php

namespace App\Domain\Libraries;

use RuntimeException;

/**
 * Thrown when a media item with an already-present EAN is added to a
 * library — manually or via bulk import (briefing 5.1). The record is
 * strictly rejected: no creation, no automatic stock increase.
 */
class DuplicateEanException extends RuntimeException
{
    public function __construct(public readonly string $ean)
    {
        parent::__construct("A media item with EAN {$ean} already exists in this library.");
    }
}
