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
}
