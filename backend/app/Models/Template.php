<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An admin-added UI template beyond the bundled light/dark
 * (briefing 10./11.4, GitHub issue #11). `code` is immutable once created
 * (TemplateController::update() never accepts it), same as LanguagePack's
 * `code` / MetadataPlugin's `provider_key`.
 */
#[Fillable(['code', 'name', 'colors'])]
class Template extends Model
{
    /**
     * The exact CSS custom-property names (minus the leading `--`) every
     * template's `colors` must define — matches frontend/src/index.css's
     * `:root`/`:root[data-template='dark']` blocks exactly, since
     * ThemeContext.tsx applies these to a runtime template via
     * document.documentElement.style.setProperty('--' + key, value)
     * one-for-one. `color-scheme` is the one non-`--`-prefixed entry (a
     * real CSS property, not a custom property, but set the same way) —
     * it's what gives native browser UI (scrollbars, form controls,
     * `<input type="color">` widgets) an OS-native dark appearance instead
     * of drawing light-themed chrome over a dark page.
     */
    public const REQUIRED_COLOR_KEYS = [
        'color-bg',
        'color-surface',
        'color-text',
        'color-text-muted',
        'color-border',
        'color-accent',
        'color-danger',
        'color-danger-bg',
        'color-scheme',
    ];

    /** `code` (e.g. "solarized") is the stable, publicly visible identifier — routes bind on it, not the numeric id. */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    protected function casts(): array
    {
        return [
            'colors' => 'array',
        ];
    }
}
