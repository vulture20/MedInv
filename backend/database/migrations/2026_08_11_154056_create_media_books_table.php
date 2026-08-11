<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Book media items (briefing 6.1). EAN is unique per library, not globally
 * (5.1) — the same EAN may exist in different libraries, but not twice in
 * the same one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained('libraries')->cascadeOnDelete();
            $table->string('title');
            $table->string('cover_path')->nullable();
            $table->text('description')->nullable();
            $table->string('authors')->nullable();
            $table->string('format')->nullable();
            $table->string('genre')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->string('language', 10)->nullable();
            $table->string('publisher')->nullable();
            $table->date('release_date')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('isbn10', 10)->nullable();
            $table->string('isbn13', 13)->nullable();
            $table->string('ean', 13);
            $table->timestamps();

            $table->unique(['library_id', 'ean']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_books');
    }
};
