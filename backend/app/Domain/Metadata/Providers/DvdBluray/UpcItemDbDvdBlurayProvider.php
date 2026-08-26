<?php

namespace App\Domain\Metadata\Providers\DvdBluray;

use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\Contracts\NameOnlyFallbackProvider;
use App\Domain\Metadata\Providers\UpcItemDb\UpcItemDbLookup;

/**
 * DVD/Blu-ray variant of the upcitemdb.com last-resort name-fallback
 * provider (GitHub issue #192) — see UpcItemDbLookup's own docblock for the
 * shared implementation and the deliberate reasoning behind its narrow
 * role. Not itself live-confirmed against a real DVD/Blu-ray barcode during
 * #192's feasibility study — the one real DVD UPC tried during that study
 * (Inception, 025192071614) came back with `total: 0` (itself uninformative
 * either way, not a negative signal) — included on the strength of
 * upcitemdb.com's own general consumer-goods coverage claim, not a
 * confirmed-live media hit the way the book variant got.
 */
class UpcItemDbDvdBlurayProvider implements MetadataProviderInterface, NameOnlyFallbackProvider
{
    use UpcItemDbLookup;

    public function key(): string
    {
        return 'dvd_bluray.upcitemdb';
    }

    public function name(): string
    {
        return 'UPCitemdb';
    }

    public function mediaType(): string
    {
        return 'dvd_bluray';
    }
}
