<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single visibility grant on a Library (briefing 4.3). See the migration
 * for the meaning of each `scope` value.
 */
#[Fillable(['library_id', 'scope', 'user_id'])]
class LibraryShare extends Model
{
    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
