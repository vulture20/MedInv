<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DVD/Blu-ray media items (briefing 6.3). See media_books migration for the
 * EAN uniqueness rule (5.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_dvd_blurays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained('libraries')->cascadeOnDelete();
            $table->string('title');
            $table->string('cover_path')->nullable();
            $table->text('description')->nullable();
            $table->string('medium')->nullable();
            $table->unsignedInteger('disc_count')->default(1);
            $table->unsignedInteger('runtime_minutes')->nullable();
            $table->string('languages')->nullable();
            $table->text('cast')->nullable();
            $table->string('director')->nullable();
            $table->date('release_date')->nullable();
            $table->unsignedSmallInteger('production_year')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('ean', 13);
            $table->timestamps();

            $table->unique(['library_id', 'ean']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_dvd_blurays');
    }
};
