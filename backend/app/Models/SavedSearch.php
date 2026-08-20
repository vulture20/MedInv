<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's named, reusable search-mask filter combination (GitHub issue
 * #73's "nice to have": "Speichern häufig genutzter Filterkombinationen").
 * Personal to the user who saved it — SavedSearchController checks
 * ownership itself rather than going through LibraryAccessService, since
 * this isn't library-scoped data at all, just a bookmark of the filter
 * params a request to GET /search would otherwise carry in its query
 * string.
 */
#[Fillable(['user_id', 'name', 'filters'])]
class SavedSearch extends Model
{
    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
