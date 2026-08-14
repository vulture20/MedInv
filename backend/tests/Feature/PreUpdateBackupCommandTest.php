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

    /**
     * Real-world bug, reported after deploying the `reason` column
     * (BackupService::create()'s docblock): this command runs *before*
     * `migrate --force` (docker/entrypoint.sh) by design — a safety-net
     * backup ahead of whatever the pending migrations are about to
     * change. On the one-time upgrade across the boundary that adds a new
     * `backups` column and also *writes* to that same column, the column
     * genuinely doesn't exist yet at the exact moment this command runs,
     * and the INSERT crashed with "no such column: reason" instead of the
     * safety-net backup succeeding. Simulated here by dropping the column
     * that RefreshDatabase's full migration run already created, then
     * running the command exactly as if that migration were still
     * pending.
     */
    public function test_still_creates_a_backup_when_the_reason_column_itself_does_not_exist_yet(): void
    {
        Storage::fake('local');
        $this->markOneMigrationAsPending();
        Schema::table('backups', fn ($table) => $table->dropColumn('reason'));

        $exitCode = Artisan::call('medinv:pre-update-backup');

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas((new Backup)->getTable(), ['trigger' => 'automatic']);
    }
}
