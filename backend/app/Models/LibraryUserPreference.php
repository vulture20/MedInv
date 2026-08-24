<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GitHub issue #179: a library's exclude-from-statistics/-reports/-dashboard
 * flags, per requesting user rather than global — see the creating
 * migration's docblock for why this replaced Library::exclude_from_
 * statistics/exclude_from_reports (GitHub issue #176). One row per
 * (library, user) pair; the absence of a row means "not excluded" for
 * every flag, the same default every flag had as a plain column before, so
 * a library nobody has ever touched this setting for needs no row at all.
 * Written via App\Http\Controllers\Api\LibraryPreferenceController, read
 * via LibraryAccessService::visibleLibrariesQueryExcluding().
 */
#[Fillable(['library_id', 'user_id', 'exclude_from_statistics', 'exclude_from_reports', 'exclude_from_dashboard'])]
class LibraryUserPreference extends Model
{
    protected function casts(): array
    {
        return [
            'exclude_from_statistics' => 'boolean',
            'exclude_from_reports' => 'boolean',
            'exclude_from_dashboard' => 'boolean',
        ];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
