<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DVD/Blu-ray media item, attribute set fixed per briefing 6.3 —
 * `currency` (GitHub issue #58), `capture_method`/`metadata_provider`/
 * `captured_by_user_id` (GitHub issue #74), `location` (GitHub issue
 * #96), `genre`/`subtitles` (GitHub issue #140), and `has_duplicates`/
 * `duplicate_count` (GitHub issue #208) are deliberate extensions beyond
 * it, see the migrations that added them for why.
 */
#[Fillable([
    'library_id', 'title', 'cover_path', 'description', 'medium', 'disc_count',
    'runtime_minutes', 'languages', 'subtitles', 'cast', 'director', 'genre',
    'release_date', 'production_year', 'price', 'currency', 'ean',
    'capture_method', 'metadata_provider', 'captured_by_user_id', 'location',
    'has_duplicates', 'duplicate_count',
])]
class MediaDvdBluray extends Model
{
    /** GitHub issue #208 — see MediaBook's identical property for why this is needed. */
    protected $attributes = [
        'has_duplicates' => false,
    ];

    protected function casts(): array
    {
        return [
            'release_date' => 'date',
            'price' => 'decimal:2',
            'has_duplicates' => 'boolean',
        ];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    /** GitHub issue #74 — who was logged in when this item was captured, null for a pre-#74 item. */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }
}
