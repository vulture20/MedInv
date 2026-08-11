<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the initial admin account created from MEDINV_ADMINUSER/PASS
 * (DatabaseSeeder, briefing 4.1) so it can never be deleted via the admin
 * UI even by another admin — every self-hosted install needs at least one
 * account nobody can accidentally lock everyone out by removing. Ordinary
 * users (including other admins created later) are deletable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_protected')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_protected');
        });
    }
};
