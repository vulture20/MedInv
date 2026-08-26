<?php

namespace App\Domain\Metadata\Providers\Book;

use App\Domain\Metadata\Contracts\MetadataProviderInterface;
use App\Domain\Metadata\Contracts\NameOnlyFallbackProvider;
use App\Domain\Metadata\Providers\UpcItemDb\UpcItemDbLookup;

/**
 * Book variant of the upcitemdb.com last-resort name-fallback provider
 * (GitHub issue #192) — see UpcItemDbLookup's own docblock for the shared
 * implementation and the deliberate reasoning behind its narrow role.
 * Confirmed live during #192's feasibility study against a real ISBN-13
 * (9780747532699, "Harry Potter and the Philosopher's Stone") to return a
 * correct title.
 */
class UpcItemDbBookProvider implements MetadataProviderInterface, NameOnlyFallbackProvider
{
    use UpcItemDbLookup;

    public function key(): string
    {
        return 'book.upcitemdb';
    }

    public function name(): string
    {
        return 'UPCitemdb';
    }

    public function mediaType(): string
    {
        return 'book';
    }
}
