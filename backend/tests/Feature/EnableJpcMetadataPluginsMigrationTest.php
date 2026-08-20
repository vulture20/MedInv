<?php

namespace Tests\Feature;

use App\Models\MetadataPlugin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026_08_20_210000_enable_jpc_metadata_plugins_by_default (GitHub issue
 * #145). DatabaseSeederTest::test_seeding_creates_the_jpc_providers_enabled()
 * covers the fresh-install case (rows are created already enabled via
 * MetadataProviderRegistry::syncToDatabase(), so there's nothing for this
 * migration to do there); this covers the actual motivating scenario the
 * migration's own docblock describes — an existing install whose JPC rows
 * were already created disabled, back when JPC was still Beta/opt-in.
 * Same RefreshDatabase/load-the-migration-class-directly approach as
 * RemoveThaliaMetadataPluginsMigrationTest, for the same reason.
 */
class EnableJpcMetadataPluginsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_up_enables_pre_existing_disabled_jpc_rows_and_leaves_everything_else_untouched(): void
    {
        MetadataPlugin::query()->create(['provider_key' => 'book.jpc', 'name' => 'JPC', 'media_type' => 'book', 'enabled' => false]);
        MetadataPlugin::query()->create(['provider_key' => 'cd.jpc', 'name' => 'JPC', 'media_type' => 'cd', 'enabled' => false]);
        MetadataPlugin::query()->create(['provider_key' => 'dvd_bluray.jpc', 'name' => 'JPC', 'media_type' => 'dvd_bluray', 'enabled' => false]);
        // An unrelated, still-Beta provider must stay disabled.
        MetadataPlugin::query()->create(['provider_key' => 'book.amazon', 'name' => 'Amazon', 'media_type' => 'book', 'enabled' => false]);

        /** @var Migration $migration */
        $migration = require base_path('database/migrations/2026_08_20_210000_enable_jpc_metadata_plugins_by_default.php');
        $migration->up();

        $table = (new MetadataPlugin)->getTable();
        $this->assertDatabaseHas($table, ['provider_key' => 'book.jpc', 'enabled' => true]);
        $this->assertDatabaseHas($table, ['provider_key' => 'cd.jpc', 'enabled' => true]);
        $this->assertDatabaseHas($table, ['provider_key' => 'dvd_bluray.jpc', 'enabled' => true]);
        $this->assertDatabaseHas($table, ['provider_key' => 'book.amazon', 'enabled' => false]);
    }

    public function test_down_disables_the_jpc_rows_again(): void
    {
        MetadataPlugin::query()->create(['provider_key' => 'book.jpc', 'name' => 'JPC', 'media_type' => 'book', 'enabled' => true]);

        /** @var Migration $migration */
        $migration = require base_path('database/migrations/2026_08_20_210000_enable_jpc_metadata_plugins_by_default.php');
        $migration->down();

        $this->assertDatabaseHas((new MetadataPlugin)->getTable(), ['provider_key' => 'book.jpc', 'enabled' => false]);
    }
}
