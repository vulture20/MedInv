<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A single failed login, used by the brute-force throttle (briefing 12.4). */
#[Fillable(['email', 'ip_address', 'attempted_at'])]
class LoginAttempt extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
        ];
    }
}
