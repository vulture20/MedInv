<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One library's item_count/total_value on one calendar day (briefing 14.,
 * GitHub issue #30), written daily by StatisticsService::snapshotAll().
 * See the creating migration's docblock for why this exists alongside a
 * created_at-derived approximation rather than replacing it outright.
 */
#[Fillable(['library_id', 'snapshot_date', 'item_count', 'total_value'])]
class LibraryValueSnapshot extends Model
{
    protected function casts(): array
    {
        return [
            // 'date:Y-m-d', not the bare 'date' cast: a plain 'date' cast still
            // writes the full 'Y-m-d H:i:s' datetime format on save (only *reading*
            // is date-only) — that mismatched the plain 'Y-m-d' string
            // StatisticsService::snapshotAll() passes into updateOrCreate()'s search
            // conditions (which go straight into a raw where() clause, not through
            // this cast), so the lookup for "does today's row already exist" never
            // matched and a second same-day run hit the unique(library_id,
            // snapshot_date) constraint instead of updating. Confirmed via a failing
            // "run the command twice the same day" test before this fix.
            'snapshot_date' => 'date:Y-m-d',
            'total_value' => 'decimal:2',
        ];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }
}
