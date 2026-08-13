<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Changes libraries.owner_id's foreign key from cascadeOnDelete() to
 * restrictOnDelete() (GitHub issue #34). A library can be shared with
 * *other* users/guests (briefing 4.3), so deleting its owner used to
 * silently cascade-delete the library — and every medium in it — out from
 * under everyone else it was shared with, with no warning at all.
 * UserController::destroy() now rejects deleting an account that still
 * owns libraries (the admin must transfer ownership first via
 * LibraryController::transferOwnership(), or delete those libraries
 * deliberately) — this migration makes that invariant hold at the
 * database level too, not just in that one controller, so it can't be
 * silently bypassed by a future code path (a queue job, a console
 * command, ...) that deletes a User without going through
 * UserController::destroy() at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('libraries', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->foreign('owner_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('libraries', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
