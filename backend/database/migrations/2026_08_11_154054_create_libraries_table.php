<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A "Bibliothek" (library) per briefing chapter 5: an independent collection
 * with a name, description and a media type fixed at creation time (not
 * changeable afterwards, see 5.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('libraries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('media_type', ['book', 'cd', 'dvd_bluray']);
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_sample_library')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('libraries');
    }
};
