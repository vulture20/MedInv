<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-library visibility/access grants, independent of the user's global
 * level (briefing 4.3). A library can be shared with:
 * - scope=guest       -> readable by all users with level "guest"
 * - scope=all_users   -> readable by every logged-in "user"-level account
 * - scope=user        -> readable by exactly the user in `user_id`
 * Admins always see/manage every library regardless of shares (4.3) and are
 * therefore not represented here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained('libraries')->cascadeOnDelete();
            $table->enum('scope', ['guest', 'all_users', 'user']);
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['library_id', 'scope', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_shares');
    }
};
