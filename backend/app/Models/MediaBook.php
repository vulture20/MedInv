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
 * #74), `location` a third (GitHub issue #96), and `has_duplicates`/
 * `duplicate_count` a fourth (GitHub issue #208), see the migrations
 * that added them.
 */
#[Fillable([
    'library_id', 'title', 'cover_path', 'description', 'authors', 'format',
    'genre', 'page_count', 'language', 'publisher', 'release_date', 'price',
    'currency', 'isbn10', 'isbn13', 'ean',
    'capture_method', 'metadata_provider', 'captured_by_user_id', 'location',
    'has_duplicates', 'duplicate_count',
])]
class MediaBook extends Model
{
    /**
     * GitHub issue #208: `has_duplicates` is `NOT NULL DEFAULT false` at
     * the DB level, but a DB-level default only ever applies to the actual
     * inserted row — it does *not* retroactively populate the in-memory
     * model `create()` returns when the attribute was never part of the
     * payload in the first place, since Eloquent doesn't re-fetch the row
     * after an insert. Confirmed live: creating an item without sending
     * `has_duplicates` at all left it `null` in the JSON response despite
     * the row itself correctly having `false`. Setting it here means every
     * newly instantiated model already starts from the same default the
     * column itself has, so the in-memory value matches the database one
     * from the moment the model exists, with no extra query needed —
     * `create()`'s own explicit `has_duplicates` (when the caller does send
     * one) still simply overwrites this via mass assignment, same as any
     * other attribute.
     */
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
