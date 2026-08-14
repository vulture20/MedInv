<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed registry of additional UI templates (briefing 10./11.4,
 * GitHub issue #11 — the reason a hardcoded Rule::in(['light','dark']) in
 * AccountSettingsController::update() could finally be replaced with a real
 * registry check). Deliberate structural mirror of language_packs (see that
 * migration's own docblock) since it's the closest existing precedent for
 * "admin-managed registry, code is the stable public identifier, two
 * bundled ones are reserved and never get a row" — `code` is what
 * Template::getRouteKeyName() resolves routes on, not the numeric id.
 * `light`/`dark` stay reserved for the two build-compiled templates
 * (frontend/src/index.css's static `:root`/`:root[data-template='dark']`
 * rules) — enforced in TemplateController::store(), not here.
 *
 * `colors` is a flat key-value map of CSS custom-property names (without
 * the leading `--`, e.g. "color-bg") to values, plus a `color-scheme`
 * entry — the exact same shape as templates/light.json / dark.json
 * (BundledTemplateRegistry) and what ThemeContext.tsx applies via
 * style.setProperty(). Unlike a language pack's `translations` (allowed to
 * be a partial object — i18next gracefully falls back per missing key),
 * TemplateController::store()/update() require every key from
 * REQUIRED_COLOR_KEYS to be present: a missing color doesn't gracefully
 * fall back to anything sensible, it just leaves that one UI element
 * unstyled/inheriting the browser default, which looks broken rather than
 * merely incomplete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->json('colors');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
