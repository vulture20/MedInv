<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metadata for created backup files (briefing 9.2 / 9.3). The actual backup
 * archives live under storage/app/private/backups (mounted as a Docker volume, see
 * docs/medinv-briefing.md chapter 19); this table tracks retention (count /
 * max age per interval mode) so BackupService can prune automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->enum('trigger', ['automatic', 'manual'])->default('automatic');
            $table->enum('interval_mode', ['daily', 'weekly', 'monthly', 'cron'])->nullable();
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
