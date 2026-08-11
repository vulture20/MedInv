<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the default Laravel users table with the fields required by
 * briefing chapter 4 (Benutzer- und Rechtemanagement):
 * - `level`: global role (Gast / Benutzer / Administrator), see 4.2.
 * - `is_active`: deactivation instead of deletion, see 4.1.
 * - `preferred_language` / `preferred_template`: per-user UI settings, see 11.4 / 10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('level', ['guest', 'user', 'admin'])
                ->default('user')
                ->after('email');
            $table->boolean('is_active')->default(true)->after('level');
            $table->string('preferred_language', 10)->default('de')->after('is_active');
            $table->string('preferred_template', 20)->default('light')->after('preferred_language');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['level', 'is_active', 'preferred_language', 'preferred_template']);
        });
    }
};
