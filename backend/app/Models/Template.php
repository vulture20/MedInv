<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An admin-added UI template beyond the bundled light/dark
 * (briefing 10./11.4, GitHub issue #11). `code` is immutable once created
 * (TemplateController::update() never accepts it), same as LanguagePack's
 * `code` / MetadataPlugin's `provider_key`.
 *
 * `css` is the literal text content of a CSS file — not a fixed set of
 * color values — injected verbatim into a <style> element by
 * ThemeContext.tsx whenever this template is selected (via `.textContent`,
 * never `.innerHTML`, specifically so admin-authored CSS can never break
 * out into markup/script regardless of its content). There is no
 * server-side schema for what it must contain beyond "non-empty string";
 * see templates/README.md for the recommended `:root { --color-bg: ...; }`
 * pattern that keeps a template visually consistent with the rest of the
 * app, which every built-in component already reads its colors from.
 */
#[Fillable(['code', 'name', 'css'])]
class Template extends Model
{
    /** `code` (e.g. "solarized") is the stable, publicly visible identifier — routes bind on it, not the numeric id. */
    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
