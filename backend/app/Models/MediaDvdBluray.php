<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** DVD/Blu-ray media item, attribute set fixed per briefing 6.3. */
#[Fillable([
    'library_id', 'title', 'cover_path', 'description', 'medium', 'disc_count',
    'runtime_minutes', 'languages', 'cast', 'director', 'release_date',
    'production_year', 'price', 'ean',
])]
class MediaDvdBluray extends Model
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
