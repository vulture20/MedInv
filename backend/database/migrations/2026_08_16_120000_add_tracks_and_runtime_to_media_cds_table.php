<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub issue #48: CDs previously had no track listing or runtime field at
 * all (briefing 6.2's fixed attribute set doesn't include either — this
 * deliberately extends it per explicit user request, same as DVD/Blu-ray
 * already has `runtime_minutes`).
 *
 * `tracks` is a JSON array of {position, title, duration_seconds} rather
 * than a separate table: a CD's track list travels as one unit with the
 * item everywhere else in the app (export/import, backup, the metadata
 * merge UI's per-field comparison) — a relational table would need its own
 * join/ordering logic in every one of those paths for something that's
 * never queried independently of its parent item.
 *
 * `runtime_seconds` (not `runtime_minutes`, unlike DVD/Blu-ray): track
 * durations are naturally second-precise, and summing them to get a total
 * would lose that precision if rounded down to whole minutes.
 * `runtime_computed` records whether that value was derived by summing
 * `tracks` (MediaItemService::create()) rather than reported directly by a
 * provider as a field of its own — surfaced in the UI as a "(computed)"
 * hint (MediaItemDetailDialog.tsx) so it's clear the number is a
 * best-effort sum, not an authoritative published runtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_cds', function (Blueprint $table) {
            $table->json('tracks')->nullable()->after('disc_count');
            $table->unsignedInteger('runtime_seconds')->nullable()->after('tracks');
            $table->boolean('runtime_computed')->default(false)->after('runtime_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('media_cds', function (Blueprint $table) {
            $table->dropColumn(['tracks', 'runtime_seconds', 'runtime_computed']);
        });
    }
};
