<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CD media item, attribute set fixed per briefing 6.2. */
#[Fillable([
    'library_id', 'title', 'cover_path', 'description', 'artist', 'medium',
    'asin', 'disc_count', 'release_date', 'price', 'ean',
])]
class MediaCd extends Model
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
