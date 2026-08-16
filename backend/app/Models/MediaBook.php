<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Book media item, attribute set fixed per briefing 6.1 — `currency` is a
 * deliberate extension beyond it (GitHub issue #58), see the migration
 * that added it for why.
 */
#[Fillable([
    'library_id', 'title', 'cover_path', 'description', 'authors', 'format',
    'genre', 'page_count', 'language', 'publisher', 'release_date', 'price',
    'currency', 'isbn10', 'isbn13', 'ean',
])]
class MediaBook extends Model
{
    protected function casts(): array
    {
        return [
            'release_date' => 'date',
            'price' => 'decimal:2',
        ];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }
}
