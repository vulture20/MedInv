<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub issue #181 (explicit user request): a "last login" column for
 * UsersPage.tsx's admin user table. Nullable, no backfill — a pre-existing
 * account's last real login simply isn't known, so it stays null (rendered
 * as "—") rather than a fabricated value, until that account's next login
 * actually sets it. Set from AuthController::login() (the ordinary
 * email/password path) and OidcAuthController::callback() (SSO) alike —
 * see both for why it's a plain forceFill()->save(), not mass assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('is_protected');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};
