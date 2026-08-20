<?php

use App\Models\MetadataPlugin;
use Illuminate\Database\Migrations\Migration;

/**
 * GitHub issue #134: the Thalia scraper (GitHub issue #129) was removed
 * from MetadataProviderRegistry::defaultProviders() — confirmed
 * permanently non-functional against thalia.de's Cloudflare bot-management
 * (GitHub issue #132), unlike every other Beta scraper in this app, which
 * are merely unreliable rather than categorically blocked.
 *
 * Without this migration, an install that had already run
 * MetadataProviderRegistry::syncToDatabase() while the Thalia providers
 * still existed would be left with three orphaned `metadata_plugins` rows
 * (`book.thalia`/`cd.thalia`/`dvd_bluray.thalia`) forever — no code class
 * matches those keys any more, so MetadataController::plugins() would
 * keep showing them with a null `version`/`source_type`/`config_fields`
 * (the same "unregistered provider key" shape
 * MetadataPluginSourceTypeTest already covers as a non-error case for a
 * hypothetical future provider, not intended as a place to leave a
 * permanently dead entry) — a confusing, still-toggleable-but-inert row
 * in PluginsPage.tsx with no way for an admin to clear it themselves.
 *
 * Deliberately not reversible: `down()` recreating these rows would just
 * immediately go stale again on the very next `syncToDatabase()` call
 * (which runs on every `db:seed --force`, i.e. every container boot per
 * docker/entrypoint.sh) since the provider classes are gone — there's
 * nothing meaningful to roll back to.
 */
return new class extends Migration
{
    public function up(): void
    {
        MetadataPlugin::query()
            ->whereIn('provider_key', ['book.thalia', 'cd.thalia', 'dvd_bluray.thalia'])
            ->delete();
    }

    public function down(): void
    {
        // See this migration's own docblock for why this is deliberately a no-op.
    }
};
