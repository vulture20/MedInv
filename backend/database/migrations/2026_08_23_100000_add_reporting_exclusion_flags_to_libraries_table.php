<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub issue #176: lets an owner/admin exclude a library from
 * StatisticsService and ReportsService respectively — e.g. a wishlist or
 * loan-tracking library that isn't really part of "the collection" and
 * would otherwise skew totals, distributions and top-lists it has no
 * business appearing in. Deliberately two independent flags, not one,
 * matching CLAUDE.md's Statistics-vs-Reports ("Auswertungen") split: an
 * admin may well want a library out of the value/growth charts but still
 * want it showing up in the data-quality/duplicates/recent-additions
 * tables, or vice versa. Both default to false so every existing library's
 * current behavior (visible everywhere LibraryAccessService already allows
 * it) is unchanged until an owner/admin opts in via LibrarySettingsDialog.tsx.
 *
 * Deliberately *not* folded into LibraryAccessService::visibleLibrariesQuery()
 * itself — that method governs whether a library is visible/writable at all
 * (briefing 4.2-4.3), a materially different question from "does this
 * visible library count toward aggregate reporting". LibraryController's
 * own index()/show() and SearchService are unaffected: a library excluded
 * here still lists, opens and is found by search exactly as before, it
 * simply stops contributing to StatisticsService/ReportsService's own
 * queries (both apply these flags on top of visibleLibrariesQuery(), see
 * their own docblocks).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('libraries', function (Blueprint $table) {
            $table->boolean('exclude_from_statistics')->default(false)->after('is_sample_library');
            $table->boolean('exclude_from_reports')->default(false)->after('exclude_from_statistics');
        });
    }

    public function down(): void
    {
        Schema::table('libraries', function (Blueprint $table) {
            $table->dropColumn(['exclude_from_statistics', 'exclude_from_reports']);
        });
    }
};
