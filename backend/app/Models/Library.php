<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A "Bibliothek" per briefing chapter 5. `media_type` is fixed at creation
 * and intentionally has no update path in LibraryController (not changeable
 * afterwards, 5.). The actual media records live in one of the three
 * type-specific tables (mediaBooks/mediaCds/mediaDvdBlurays) depending on
 * this value.
 */
#[Fillable(['name', 'description', 'media_type', 'owner_id', 'is_sample_library'])]
class Library extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_sample_library' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(LibraryShare::class);
    }

    public function mediaBooks(): HasMany
    {
        return $this->hasMany(MediaBook::class);
    }

    public function mediaCds(): HasMany
    {
        return $this->hasMany(MediaCd::class);
    }

    public function mediaDvdBlurays(): HasMany
    {
        return $this->hasMany(MediaDvdBluray::class);
    }

    /** The type-specific media relation matching `media_type` (see 6.). */
    public function mediaItems(): HasMany
    {
        return match ($this->media_type) {
            'book' => $this->mediaBooks(),
            'cd' => $this->mediaCds(),
            'dvd_bluray' => $this->mediaDvdBlurays(),
        };
    }
}
