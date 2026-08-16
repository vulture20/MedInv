<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CD media item, attribute set fixed per briefing 6.2 — `tracks`/
 * `runtime_seconds`/`runtime_computed` (GitHub issue #48) and `currency`
 * (GitHub issue #58) are deliberate extensions beyond it, see the
 * migrations that added them for why.
 */
#[Fillable([
    'library_id', 'title', 'cover_path', 'description', 'artist', 'medium',
    'asin', 'disc_count', 'tracks', 'runtime_seconds', 'runtime_computed',
    'release_date', 'price', 'currency', 'ean',
])]
class MediaCd extends Model
{
    protected function casts(): array
    {
        return [
            'release_date' => 'date',
            'price' => 'decimal:2',
            'tracks' => 'array',
            'runtime_computed' => 'boolean',
        ];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }
}
