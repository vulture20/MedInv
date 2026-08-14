<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `reason` (added alongside `trigger`) distinguishes *why* an automatic
 * backup was created — 'scheduled' (the admin-configured interval,
 * routes/console.php) vs 'pre_update' (PreUpdateBackupCommand, ahead of a
 * pending migration) — surfaced to the admin UI via GET /admin/backups so
 * "automatic" alone no longer has to be taken on faith. ScheduledBackupTest
 * and PreUpdateBackupCommandTest each cover their own path's reason value
 * at the point of creation; this covers it end-to-end through the actual
 * HTTP API a step further, and that a manually-created backup has no
 * reason at all (trigger='manual' already fully explains itself).
 */
class BackupReasonTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_a_manually_created_backup_has_no_reason(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/backups');

        $response->assertCreated();
        $response->assertJsonPath('trigger', 'manual');
        $response->assertJsonPath('reason', null);
        $this->assertDatabaseHas((new Backup)->getTable(), ['trigger' => 'manual', 'reason' => null]);
    }

    public function test_the_backup_list_exposes_the_reason_for_every_kind(): void
    {
        $this->actingAsAdmin();
        Backup::query()->create([
            'filename' => 'medinv-backup-manual.zip', 'trigger' => 'manual', 'reason' => null, 'status' => 'completed',
        ]);
        Backup::query()->create([
            'filename' => 'medinv-backup-scheduled.zip', 'trigger' => 'automatic', 'reason' => 'scheduled',
            'interval_mode' => 'daily', 'status' => 'completed',
        ]);
        Backup::query()->create([
            'filename' => 'medinv-backup-preupdate.zip', 'trigger' => 'automatic', 'reason' => 'pre_update', 'status' => 'completed',
        ]);

        $response = $this->getJson('/api/admin/backups');

        $response->assertOk();
        $byFilename = collect($response->json())->keyBy('filename');
        $this->assertNull($byFilename['medinv-backup-manual.zip']['reason']);
        $this->assertSame('scheduled', $byFilename['medinv-backup-scheduled.zip']['reason']);
        $this->assertSame('pre_update', $byFilename['medinv-backup-preupdate.zip']['reason']);
    }
}
