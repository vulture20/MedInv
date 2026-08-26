<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub issue #194: a personal preference for how many results
 * MediaItemController::index() returns per page (briefing 4.1
 * "benutzerdefinierte Einstellungen"), previously hardcoded to 50 with no
 * way to change it outside of a raw API call. Restricted at the
 * application layer to App\Models\User::ITEMS_PER_PAGE_OPTIONS
 * (20/50/100/200) via AccountSettingsController::update()'s validation —
 * an unsigned smallint here is deliberately more permissive than that
 * fixed list, the same "the column itself just stores a plausible integer,
 * the app enforces the real constraint" split every other enum-like
 * preference in this app already has (e.g. `level`, which *is* a DB-level
 * enum, chose the stricter option; this one doesn't need to, since 200 is
 * comfortably far from any value that would cause real problems even if a
 * hand-crafted request slipped past validation somehow).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('items_per_page')->default(50)->after('preferred_template');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('items_per_page');
        });
    }
};
