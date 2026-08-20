<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Mail\PasswordResetMail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\HasApiTokens;

/**
 * Global user levels per briefing 4.2: guest / user / admin. Per-library
 * read access on top of that is governed by LibraryShare (4.3), not by this
 * model.
 */
#[Fillable(['name', 'email', 'password', 'level', 'is_active', 'is_protected', 'preferred_language', 'preferred_template', 'oidc_subject'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_protected' => 'boolean',
        ];
    }

    /**
     * Overrides Illuminate\Auth\Passwords\CanResetPassword's default (send
     * Laravel's built-in ResetPassword notification, which uses Laravel's
     * own markdown mail theme) so a reset request instead sends our own
     * branded, bilingual PasswordResetMail — see that class's docblock for
     * why (briefing 12.3, the Laravel-branded reset email bug report).
     */
    public function sendPasswordResetNotification($token): void
    {
        Mail::to($this)->send(new PasswordResetMail($this, $token));
    }

    public function isAdmin(): bool
    {
        return $this->level === 'admin';
    }

    public function isGuest(): bool
    {
        return $this->level === 'guest';
    }

    /** Libraries created/owned by this user (briefing 5.). */
    public function ownedLibraries(): HasMany
    {
        return $this->hasMany(Library::class, 'owner_id');
    }

    /** Explicit per-library shares granted to this user (briefing 4.3). */
    public function libraryShares(): HasMany
    {
        return $this->hasMany(LibraryShare::class);
    }

    /** Named, reusable search-mask filter combinations (GitHub issue #73's "nice to have"). */
    public function savedSearches(): HasMany
    {
        return $this->hasMany(SavedSearch::class);
    }
}
