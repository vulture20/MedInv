<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `trigger` (automatic/manual) only says *whether* a human clicked
 * "create backup now" — for an automatic backup it doesn't say which of
 * the two independent automatic paths actually created it: the admin-
 * configured schedule (routes/console.php, briefing 9.2) or
 * PreUpdateBackupCommand's safety net ahead of a pending database
 * migration (briefing 9.3-adjacent, "Datenbank vorbereiten..."). Nullable
 * and only ever populated for trigger=automatic — a manual backup's
 * "reason" is already fully explained by trigger itself, an admin
 * explicitly clicking the button.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->enum('reason', ['scheduled', 'pre_update'])->nullable()->after('trigger');
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
