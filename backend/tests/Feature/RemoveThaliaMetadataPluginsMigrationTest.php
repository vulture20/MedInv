<?php

namespace Tests\Feature;

use App\Models\MetadataPlugin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026_08_20_193000_remove_thalia_metadata_plugins (GitHub issue #134).
 * DatabaseSeederTest::test_seeding_does_not_recreate_the_removed_thalia_providers()
 * covers the fresh-install case (nothing to clean up, since the rows
 * never existed there); this covers the actual motivating scenario the
 * migration's own docblock describes — an existing install that already
 * had these rows from before the removal. RefreshDatabase's migrate step
 * runs every migration, including this one, against an empty database
 * before any test method's own body executes, so the only way to
 * exercise `up()` against genuinely pre-existing data is to load and
 * re-run the migration class directly, as done here.
 */
class RemoveThaliaMetadataPluginsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_up_deletes_pre_existing_thalia_rows_and_leaves_everything_else_untouched(): void
    {
        MetadataPlugin::query()->create(['provider_key' => 'book.thalia', 'name' => 'Thalia', 'media_type' => 'book', 'enabled' => false]);
        MetadataPlugin::query()->create(['provider_key' => 'cd.thalia', 'name' => 'Thalia', 'media_type' => 'cd', 'enabled' => false]);
        MetadataPlugin::query()->create(['provider_key' => 'dvd_bluray.thalia', 'name' => 'Thalia', 'media_type' => 'dvd_bluray', 'enabled' => false]);
        MetadataPlugin::query()->create(['provider_key' => 'cd.jpc', 'name' => 'JPC', 'media_type' => 'cd', 'enabled' => false]);

        /** @var Migration $migration */
        $migration = require base_path('database/migrations/2026_08_20_193000_remove_thalia_metadata_plugins.php');
        $migration->up();

        $table = (new MetadataPlugin)->getTable();
        $this->assertDatabaseMissing($table, ['provider_key' => 'book.thalia']);
        $this->assertDatabaseMissing($table, ['provider_key' => 'cd.thalia']);
        $this->assertDatabaseMissing($table, ['provider_key' => 'dvd_bluray.thalia']);
        // An unrelated provider's own row must survive untouched.
        $this->assertDatabaseHas($table, ['provider_key' => 'cd.jpc']);
    }
}
