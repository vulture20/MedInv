<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry of metadata source plugins (briefing 8). Each row corresponds to
 * one class implementing MetadataProviderInterface
 * (app/Domain/Metadata/Contracts), registered under `provider_key` and
 * scoped to a single media type. Admins enable/disable plugins here (15.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metadata_plugins', function (Blueprint $table) {
            $table->id();
            $table->string('provider_key')->unique();
            $table->string('name');
            $table->enum('media_type', ['book', 'cd', 'dvd_bluray']);
            $table->boolean('enabled')->default(true);
            $table->json('config')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metadata_plugins');
    }
};
