<?php

namespace Tests\Feature;

use App\Models\Backup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `medinv:pre-update-backup` (PreUpdateBackupCommand), run from
 * docker/entrypoint.sh immediately before `migrate --force` on every
 * container start. Previously untested. "Pending migrations" is simulated
 * by deleting one row from the `migrations` table after RefreshDatabase
 * already ran everything — the corresponding migration file still exists
 * on disk, but the migration repository no longer lists it as ran, which
 * is exactly what the command's own array_diff() check looks at.
 */
class PreUpdateBackupCommandTest extends TestCase
{
    use RefreshDatabase;

    private function markOneMigrationAsPending(): void
    {
        DB::table('migrations')->orderByDesc('id')->limit(1)->delete();
    }

    public function test_creates_a_backup_with_reason_pre_update_when_migrations_are_pending(): void
    {
        Storage::fake('local');
        $this->markOneMigrationAsPending();

        $exitCode = Artisan::call('medinv:pre-update-backup');

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas((new Backup)->getTable(), ['trigger' => 'automatic', 'reason' => 'pre_update']);
    }

    public function test_skips_when_there_are_no_pending_migrations(): void
    {
        Storage::fake('local');

        Artisan::call('medinv:pre-update-backup');

        $this->assertSame(0, Backup::query()->count());
        $this->assertStringContainsString('No pending migrations', Artisan::output());
    }

    public function test_skips_on_a_fresh_install_with_no_migrations_table_at_all(): void
    {
        Storage::fake('local');
        Schema::dropIfExists('migrations');

        Artisan::call('medinv:pre-update-backup');

        $this->assertSame(0, Backup::query()->count());
        $this->assertStringContainsString('Fresh install', Artisan::output());
    }
}
