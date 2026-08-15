<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub issue #16: OpenID Connect / OAuth 2.0 login (Pocket ID or any
 * spec-compliant provider). `oidc_subject` stores the ID token's `sub`
 * claim — the provider-scoped, stable identifier OidcAuthController links
 * a User to on every subsequent login, rather than re-matching by email
 * each time (an email address can change at the provider; `sub` is
 * defined by the OIDC spec to never change for a given account). Nullable
 * and unique: most users will never have one (password-only accounts),
 * and a unique index still allows any number of NULLs on every backend
 * this app supports (sqlite/mariadb/pgsql all treat NULL as distinct
 * under a unique constraint) while still preventing two different local
 * accounts from ever claiming the same provider identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('oidc_subject')->nullable()->unique()->after('preferred_template');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('oidc_subject');
        });
    }
};
