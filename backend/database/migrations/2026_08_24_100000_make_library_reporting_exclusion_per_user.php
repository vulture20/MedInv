<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub issue #179: GitHub issue #176's exclude_from_statistics/
 * exclude_from_reports were global, admin/owner-set columns on `libraries`
 * — every user saw the exact same Statistics/Reports scope for a given
 * library. The user explicitly asked for this to become a per-user
 * preference instead (each user decides for themselves whether a library
 * counts toward *their own* Statistics/Reports/Dashboard), plus a third,
 * new toggle covering the Startseite's random-cover carousels
 * (DashboardController::randomItems() -> SearchService::randomItemsFor()),
 * which never had an exclusion mechanism at all before this.
 *
 * `library_user_preferences` replaces those two columns with one row per
 * (library, user) pair — the absence of a row means "not excluded" for
 * every flag, the same default the plain columns had, so a library nobody
 * has ever touched this setting for needs no row at all. See
 * App\Models\LibraryUserPreference and LibraryAccessService::
 * visibleLibrariesQueryExcluding() for how this is read; App\Http\
 * Controllers\Api\LibraryPreferenceController for how it's written
 * (canRead(), not canWrite()/canManage() — this is a personal setting
 * anyone who can see the library may set for themselves, not a
 * library-management action restricted to its owner/an admin).
 *
 * Data migration: a library previously flagged exclude_from_statistics/
 * exclude_from_reports affected *every* user identically — that was the
 * whole point of it being a global setting — so every existing user
 * account gets that same starting value as their own initial preference
 * row for that library, preserving current behavior instead of silently
 * un-excluding it for everyone the moment this migration runs. From here
 * on each user's row evolves independently. exclude_from_dashboard has no
 * previous global equivalent to migrate from — every user simply starts
 * included, same as the Startseite's behavior before this feature existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('exclude_from_statistics')->default(false);
            $table->boolean('exclude_from_reports')->default(false);
            $table->boolean('exclude_from_dashboard')->default(false);
            $table->timestamps();
            $table->unique(['library_id', 'user_id']);
        });

        $userIds = DB::table('users')->pluck('id');
        $flaggedLibraries = DB::table('libraries')
            ->where('exclude_from_statistics', true)
            ->orWhere('exclude_from_reports', true)
            ->get(['id', 'exclude_from_statistics', 'exclude_from_reports']);

        $now = now();
        $rows = [];
        foreach ($flaggedLibraries as $library) {
            foreach ($userIds as $userId) {
                $rows[] = [
                    'library_id' => $library->id,
                    'user_id' => $userId,
                    'exclude_from_statistics' => $library->exclude_from_statistics,
                    'exclude_from_reports' => $library->exclude_from_reports,
                    'exclude_from_dashboard' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('library_user_preferences')->insert($chunk);
        }

        Schema::table('libraries', function (Blueprint $table) {
            $table->dropColumn(['exclude_from_statistics', 'exclude_from_reports']);
        });
    }

    public function down(): void
    {
        Schema::table('libraries', function (Blueprint $table) {
            $table->boolean('exclude_from_statistics')->default(false)->after('is_sample_library');
            $table->boolean('exclude_from_reports')->default(false)->after('exclude_from_statistics');
        });

        Schema::dropIfExists('library_user_preferences');
    }
};
