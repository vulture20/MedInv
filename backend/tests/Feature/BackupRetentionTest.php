<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupService;
use App\Models\Backup;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BackupService::prune() (briefing 9.2, with one deliberate deviation from
 * it — see below). Manual backups (trigger='manual', an admin explicitly
 * clicking "Create backup now") are deliberately exempt from retention
 * entirely, and don't consume a slot in the automatic-backup count either —
 * reported because a manual backup taken on purpose (e.g. right before a
 * risky change) was getting silently deleted by the automatic-backup
 * retention policy, which is meant to bound *unattended* backup
 * accumulation, not backups an admin asked for by name.
 *
 * Deviation from briefing 9.2: it literally says both the age and count
 * limits apply simultaneously ("... überschreiten ODER ... hinausgehen").
 * Having both editable and both silently in effect at once was reported as
 * confusing rather than useful (whichever rule is stricter always wins
 * invisibly) — `backup.retention_mode` ('count', the default, or 'age')
 * now picks exactly one active criterion; the other's stored value is
 * simply not consulted while that mode is in effect.
 */
class BackupRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Backup rows only — no real zip file needed, prune()/delete() tolerate a missing on-disk file the same way an already-cleaned-up backup would. */
    private function makeBackup(string $trigger, Carbon $createdAt, string $filename): Backup
    {
        $backup = Backup::query()->create([
            'filename' => $filename,
            'size_bytes' => 100,
            'trigger' => $trigger,
            'status' => 'completed',
        ]);
        $backup->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $backup;
    }

    public function test_default_retention_mode_is_count(): void
    {
        $this->assertSame('count', SystemSetting::defaults()['backup.retention_mode']);
    }

    public function test_prune_deletes_automatic_backups_beyond_the_configured_count(): void
    {
        SystemSetting::set('backup.retention_mode', 'count');
        SystemSetting::set('backup.retention_count', 2);
        $now = Carbon::now();
        $this->makeBackup('automatic', $now->copy()->subDays(3), 'a1.zip');
        $this->makeBackup('automatic', $now->copy()->subDays(2), 'a2.zip');
        $this->makeBackup('automatic', $now->copy()->subDays(1), 'a3.zip');
        $newest = $this->makeBackup('automatic', $now, 'a4.zip');

        app(BackupService::class)->prune();

        $this->assertSame(2, Backup::query()->count());
        $this->assertDatabaseHas((new Backup)->getTable(), ['id' => $newest->id]);
    }

    public function test_prune_deletes_automatic_backups_older_than_the_configured_max_age(): void
    {
        SystemSetting::set('backup.retention_mode', 'age');
        SystemSetting::set('backup.retention_max_age_days', 5);
        $this->makeBackup('automatic', Carbon::now()->subDays(10), 'old.zip');
        $recent = $this->makeBackup('automatic', Carbon::now()->subDay(), 'recent.zip');

        app(BackupService::class)->prune();

        $this->assertSame(1, Backup::query()->count());
        $this->assertDatabaseHas((new Backup)->getTable(), ['id' => $recent->id]);
    }

    /**
     * The actual mutual-exclusivity behavior this deviation is about: an
     * age threshold that would otherwise delete everything has no effect
     * at all while mode='count' — briefing 9.2's literal "ODER" wording
     * would delete both backups here (both fail the tight max-age *and*
     * the count only allows 1), this only enforces the count.
     */
    public function test_the_max_age_value_has_no_effect_while_retention_mode_is_count(): void
    {
        SystemSetting::set('backup.retention_mode', 'count');
        SystemSetting::set('backup.retention_count', 2);
        SystemSetting::set('backup.retention_max_age_days', 1);
        $this->makeBackup('automatic', Carbon::now()->subDays(100), 'ancient.zip');
        $this->makeBackup('automatic', Carbon::now()->subDays(50), 'old.zip');

        app(BackupService::class)->prune();

        $this->assertSame(2, Backup::query()->count());
    }

    /** Mirror of the above: a tight count limit has no effect while mode='age'. */
    public function test_the_retention_count_value_has_no_effect_while_retention_mode_is_age(): void
    {
        SystemSetting::set('backup.retention_mode', 'age');
        SystemSetting::set('backup.retention_count', 1);
        SystemSetting::set('backup.retention_max_age_days', 365);
        $this->makeBackup('automatic', Carbon::now()->subDays(3), 'a1.zip');
        $this->makeBackup('automatic', Carbon::now()->subDays(2), 'a2.zip');
        $this->makeBackup('automatic', Carbon::now()->subDay(), 'a3.zip');

        app(BackupService::class)->prune();

        $this->assertSame(3, Backup::query()->count());
    }

    public function test_manual_backups_are_never_pruned_by_count(): void
    {
        SystemSetting::set('backup.retention_mode', 'count');
        SystemSetting::set('backup.retention_count', 1);
        $now = Carbon::now();
        $this->makeBackup('manual', $now->copy()->subDays(3), 'm1.zip');
        $this->makeBackup('manual', $now->copy()->subDays(2), 'm2.zip');
        $this->makeBackup('manual', $now->copy()->subDays(1), 'm3.zip');

        app(BackupService::class)->prune();

        $this->assertSame(3, Backup::query()->count());
    }

    public function test_manual_backups_are_never_pruned_by_age(): void
    {
        SystemSetting::set('backup.retention_mode', 'age');
        SystemSetting::set('backup.retention_max_age_days', 1);
        $veryOldManual = $this->makeBackup('manual', Carbon::now()->subDays(100), 'old-manual.zip');
        $this->makeBackup('automatic', Carbon::now()->subDays(100), 'old-automatic.zip');

        app(BackupService::class)->prune();

        $this->assertSame(1, Backup::query()->count());
        $this->assertDatabaseHas((new Backup)->getTable(), ['id' => $veryOldManual->id]);
    }

    public function test_manual_backups_do_not_take_up_slots_in_the_automatic_retention_count(): void
    {
        SystemSetting::set('backup.retention_mode', 'count');
        SystemSetting::set('backup.retention_count', 2);
        $now = Carbon::now();
        // Five manual backups — far more than the count=2 limit, but that limit
        // must only ever apply to automatic ones.
        for ($i = 0; $i < 5; $i++) {
            $this->makeBackup('manual', $now->copy()->subMinutes($i), "m{$i}.zip");
        }
        $this->makeBackup('automatic', $now->copy()->subDays(3), 'a1.zip');
        $this->makeBackup('automatic', $now->copy()->subDays(2), 'a2.zip');
        $newestAutomatic = $this->makeBackup('automatic', $now->copy()->subDay(), 'a3.zip');

        app(BackupService::class)->prune();

        $this->assertSame(5, Backup::query()->where('trigger', 'manual')->count());
        $this->assertSame(2, Backup::query()->where('trigger', 'automatic')->count());
        $this->assertDatabaseHas((new Backup)->getTable(), ['id' => $newestAutomatic->id]);
    }

    public function test_creating_a_manual_backup_survives_its_own_triggered_prune_under_a_tight_limit(): void
    {
        SystemSetting::set('backup.retention_mode', 'count');
        SystemSetting::set('backup.retention_count', 0);

        $backup = app(BackupService::class)->create('manual');

        $this->assertDatabaseHas((new Backup)->getTable(), ['id' => $backup->id]);
    }
}
