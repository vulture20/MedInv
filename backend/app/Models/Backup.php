<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Tracked backup archive (briefing 9.2/9.3). See the migration for fields. */
#[Fillable(['filename', 'size_bytes', 'trigger', 'interval_mode', 'status'])]
class Backup extends Model
{
    //
}
