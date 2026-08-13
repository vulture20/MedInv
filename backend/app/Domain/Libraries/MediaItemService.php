<?php

namespace App\Domain\Libraries;

use App\Models\Library;
use App\Models\MediaBook;
use App\Models\MediaCd;
use App\Models\MediaDvdBluray;
use Illuminate\Database\Eloquent\Model;

/**
 * Type-agnostic create path for media items, used by both the single-entry
 * form and bulk import (briefing 7.1/7.2). Enforces the per-library EAN
 * uniqueness rule from 5.1 for every entry point so the rule can't be
 * bypassed by adding a new capture method later.
 */
class MediaItemService
{
    /** @throws DuplicateEanException */
    public function create(Library $library, array $attributes): Model
    {
        $modelClass = $this->modelClassFor($library->media_type);

        if ($modelClass::query()->where('library_id', $library->id)->where('ean', $attributes['ean'])->exists()) {
            throw new DuplicateEanException($attributes['ean']);
        }

        return $modelClass::query()->create([...$attributes, 'library_id' => $library->id]);
    }

    /** @return class-string<Model> */
    public function modelClassFor(string $mediaType): string
    {
        return match ($mediaType) {
            'book' => MediaBook::class,
            'cd' => MediaCd::class,
            'dvd_bluray' => MediaDvdBluray::class,
        };
    }

    /**
     * Re-parents a media item into a different library (media item detail
     * dialog's "move" action). The two libraries' media_type must already
     * match by the time this is called — the record's own class (MediaBook/
     * MediaCd/MediaDvdBluray, per briefing 6.'s fixed-per-type table split)
     * only ever fits one specific media_type's table, so moving it into a
     * library of a different media_type isn't just disallowed by policy,
     * it's structurally meaningless; the caller (MediaItemController::move())
     * is responsible for that check plus the write-access checks on both
     * libraries. The destination's own per-library duplicate-EAN rule (5.1)
     * still applies here exactly as it does on create() — moving a book into
     * a library that already holds a copy with the same EAN is rejected the
     * same way a fresh capture of it would be.
     *
     * @throws DuplicateEanException
     */
    public function move(Model $item, Library $destination): void
    {
        $modelClass = $this->modelClassFor($destination->media_type);

        $duplicateExists = $modelClass::query()
            ->where('library_id', $destination->id)
            ->where('ean', $item->ean)
            ->where('id', '!=', $item->id)
            ->exists();

        if ($duplicateExists) {
            throw new DuplicateEanException($item->ean);
        }

        $item->update(['library_id' => $destination->id]);
    }
}
