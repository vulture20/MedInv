<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Book media item, attribute set fixed per briefing 6.1 — `currency` is a
 * deliberate extension beyond it (GitHub issue #58), see the migration
 * that added it for why. `capture_method`/`metadata_provider`/
 * `captured_by_user_id` are a second deliberate extension (GitHub issue
 * #74), and `location` a third (GitHub issue #96), see the migrations
 * that added them.
 */
#[Fillable([
    'library_id', 'title', 'cover_path', 'description', 'authors', 'format',
    'genre', 'page_count', 'language', 'publisher', 'release_date', 'price',
    'currency', 'isbn10', 'isbn13', 'ean',
    'capture_method', 'metadata_provider', 'captured_by_user_id', 'location',
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

    /** GitHub issue #74 — who was logged in when this item was captured, null for a pre-#74 item. */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }
}
