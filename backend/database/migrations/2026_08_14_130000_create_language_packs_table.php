<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed registry of additional UI language packs (briefing 11.4/17.,
 * GitHub issue #12) — the backend-enforcement half; #15 is the frontend
 * admin UI + runtime loading. Mirrors metadata_plugins as the nearest
 * precedent for an admin-managed registry table: `code` is the stable,
 * publicly visible identifier (like `provider_key` there), so
 * LanguagePack::getRouteKeyName() resolves it instead of the numeric id.
 * `de`/`en` stay reserved for the two bundled packs
 * (frontend/src/i18n/locales/{de,en}.json) — enforced in
 * LanguagePackController::store(), not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('language_packs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->json('translations');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('language_packs');
    }
};
