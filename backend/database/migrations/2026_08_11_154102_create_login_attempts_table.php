<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Failed login attempts, used by the brute-force throttle (briefing 12.4):
 * default 6 failures within 5 minutes locks the account for 30 minutes,
 * both values configurable via system_settings. Requests from the trusted
 * IP range (MEDINV_TRUSTEDIP) are exempt and never recorded here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('attempted_at');
        });

        Schema::table('login_attempts', function (Blueprint $table) {
            $table->index(['email', 'attempted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};
