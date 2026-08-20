<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub issue #73's "nice to have": named, reusable filter combinations
 * for the search mask, personal to the user who saved them. `filters` is
 * the whole flat filter param set as JSON (mirrors the URL query string
 * SearchPage.tsx already keeps in sync for bookmarking, GitHub issue #73's
 * own "technical implications" note) rather than a fixed set of columns —
 * a saved search should keep working as new filter dimensions are added
 * later, without a migration per dimension.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->json('filters');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
    }
};
