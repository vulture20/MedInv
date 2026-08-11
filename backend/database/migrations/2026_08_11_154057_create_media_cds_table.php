<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CD media items (briefing 6.2). See media_books migration for the EAN
 * uniqueness rule (5.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_cds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained('libraries')->cascadeOnDelete();
            $table->string('title');
            $table->string('cover_path')->nullable();
            $table->text('description')->nullable();
            $table->string('artist')->nullable();
            $table->string('medium')->nullable();
            $table->string('asin')->nullable();
            $table->unsignedInteger('disc_count')->default(1);
            $table->date('release_date')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('ean', 13);
            $table->timestamps();

            $table->unique(['library_id', 'ean']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_cds');
    }
};
