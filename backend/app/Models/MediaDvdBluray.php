<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DVD/Blu-ray media item, attribute set fixed per briefing 6.3 —
 * `currency` (GitHub issue #58), `capture_method`/`metadata_provider`/
 * `captured_by_user_id` (GitHub issue #74) and `location` (GitHub issue
 * #96) are deliberate extensions beyond it, see the migrations that added
 * them for why.
 */
#[Fillable([
    'library_id', 'title', 'cover_path', 'description', 'medium', 'disc_count',
    'runtime_minutes', 'languages', 'cast', 'director', 'release_date',
    'production_year', 'price', 'currency', 'ean',
    'capture_method', 'metadata_provider', 'captured_by_user_id', 'location',
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

    /** GitHub issue #74 — who was logged in when this item was captured, null for a pre-#74 item. */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }
}
