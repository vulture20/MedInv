<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub issue #79: every existing share (guest/all_users/user scope,
 * briefing 4.3) only ever grants read access — the only way to give another
 * user write access to a library was transferring ownership outright
 * (GitHub issue #34), which also hands over deletion rights and share
 * management, and strips the previous owner of write access entirely. This
 * deliberately extends briefing 4.3's original "jeweils mit Lesezugriff"
 * scope (same kind of intentional extension as e.g. GitHub issue #58's
 * currency field beyond briefing 6.1-6.3's fixed attribute set) rather than
 * something the briefing itself specifies.
 *
 * Defaults to 'read' so every pre-existing row (and any plain {scope,
 * user_id} payload from before this field existed) keeps its current,
 * read-only meaning unchanged. `LibraryAccessService::canWrite()` is the
 * only place that reads this column for a decision — `canRead()` doesn't
 * care about it at all, since even a 'write' share still implies read
 * access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_shares', function (Blueprint $table) {
            $table->enum('access_level', ['read', 'write'])->default('read')->after('scope');
        });
    }

    public function down(): void
    {
        Schema::table('library_shares', function (Blueprint $table) {
            $table->dropColumn('access_level');
        });
    }
};
