<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central system configuration (briefing 15.): mail server, backup
 * interval/retention, brute-force thresholds, loglevel, etc. Modeled as a
 * flat key-value store rather than dedicated columns so new setting groups
 * can be added without further migrations, in line with the "maximale
 * Modularität" principle (10.). Read/written via SystemSetting (app/Models)
 * and the AdminSettingsController.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
