<?php

namespace App\Domain\Metadata\Providers\Cd;

use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\Contracts\NameOnlyFallbackProvider;
use App\Domain\Metadata\Providers\UpcItemDb\UpcItemDbLookup;

/**
 * CD variant of the upcitemdb.com last-resort name-fallback provider
 * (GitHub issue #192) — see UpcItemDbLookup's own docblock for the shared
 * implementation and the deliberate reasoning behind its narrow role.
 * Unlike the book variant, not itself live-confirmed against a real CD
 * barcode during #192's feasibility study (the one real DVD UPC tried came
 * back with `total: 0`, itself uninformative either way) — included on the
 * strength of upcitemdb.com's own general "books, musical albums, or other
 * publications" coverage claim, not a confirmed-live media hit.
 */
class UpcItemDbCdProvider implements MetadataProviderInterface, NameOnlyFallbackProvider
{
    use UpcItemDbLookup;

    public function key(): string
    {
        return 'cd.upcitemdb';
    }

    public function name(): string
    {
        return 'UPCitemdb';
    }

    public function mediaType(): string
    {
        return 'cd';
    }
}
