<?php

use App\Models\MetadataPlugin;
use Illuminate\Database\Migrations\Migration;

/**
 * GitHub issue #145: the three JPC providers (`book.jpc`/`cd.jpc`/
 * `dvd_bluray.jpc`) are promoted out of
 * `MetadataProviderRegistry::DEFAULT_DISABLED_PROVIDER_KEYS` — an
 * explicit user decision, made after the string of real-world fixes
 * (#133, #135, #136, #138, #140, #143, #144) made JPC reliable enough to
 * enable by default, unlike Amazon, which remains Beta/opt-in.
 *
 * That code change alone only reaches a *fresh* install:
 * `MetadataProviderRegistry::syncToDatabase()` uses `firstOrCreate()`,
 * which never touches a `metadata_plugins` row that already exists —
 * every existing install's three JPC rows were created with
 * `enabled=false` back when JPC was still Beta/opt-in, and would stay
 * that way forever without this migration explicitly flipping them, the
 * same "reach every existing install, not just a fresh one" concern
 * `syncToDatabase()`'s own docblock already documents for keeping
 * `name`/`media_type` in sync with code.
 *
 * Deliberately unconditional (not "only if still at its original
 * false"): unlike GitHub issue #134's Thalia removal, there's no way for
 * an admin to have manually disabled JPC and have that preference look
 * any different in the database than "never touched, still at the old
 * default" — both are stored as plain `enabled=false`. Given the user's
 * own explicit instruction that JPC should now be enabled by default,
 * this migration is intentionally unconditional (this app's admin
 * settings model — MEDINV_ADMINUSER/MEDINV_ADMINPASS from briefing
 * 16. — is a single admin account, not a multi-tenant fleet of
 * independent operators with differing preferences to preserve).
 */
return new class extends Migration
{
    public function up(): void
    {
        MetadataPlugin::query()
            ->whereIn('provider_key', ['book.jpc', 'cd.jpc', 'dvd_bluray.jpc'])
            ->update(['enabled' => true]);
    }

    public function down(): void
    {
        MetadataPlugin::query()
            ->whereIn('provider_key', ['book.jpc', 'cd.jpc', 'dvd_bluray.jpc'])
            ->update(['enabled' => false]);
    }
};
