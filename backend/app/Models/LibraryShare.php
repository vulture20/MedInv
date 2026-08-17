<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single visibility grant on a Library (briefing 4.3). See the migration
 * for the meaning of each `scope` value. `access_level` ('read'|'write',
 * default 'read', GitHub issue #79) is a deliberate extension beyond
 * briefing 4.3's original read-only shares — see that migration's docblock.
 */
#[Fillable(['library_id', 'scope', 'user_id', 'access_level'])]
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
