<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An admin-added UI language pack beyond the bundled German/English
 * (briefing 11.4/17., GitHub issue #12). `code` is immutable once created
 * (LanguagePackController::update() never accepts it), same as
 * MetadataPlugin's `provider_key`.
 */
#[Fillable(['code', 'name', 'translations'])]
class LanguagePack extends Model
{
    /** `code` (e.g. "fr") is the stable, publicly visible identifier — routes bind on it, not the numeric id. */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    protected function casts(): array
    {
        return [
            'translations' => 'array',
        ];
    }
}
