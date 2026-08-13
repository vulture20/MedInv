<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupService;
use App\Models\Library;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GitHub issue #31: BackupService::create()'s filename used to always
 * embed UTC (now()), regardless of the admin-configured `timezone`
 * setting — see SystemSetting::localNow()'s docblock for the fix and why
 * it's scoped to just filenames/text, not stored timestamps.
 */
class BackupFilenameTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_backup_filename_uses_the_configured_timezone_not_utc(): void
    {
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-08-13 01:30:00', 'UTC'));
        SystemSetting::set('timezone', 'Europe/Berlin');

        $backup = app(BackupService::class)->create();

        // 01:30 UTC + 2h CEST = 03:30 local — crosses into the next calendar
        // day, exercising more than just the hour component.
        $this->assertStringContainsString('20260813-0330', $backup->filename);
        $this->assertStringNotContainsString('20260813-0130', $backup->filename);
    }

    public function test_backup_filename_stays_utc_when_unconfigured(): void
    {
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-08-13 01:30:00', 'UTC'));

        $backup = app(BackupService::class)->create();

        $this->assertStringContainsString('20260813-0130', $backup->filename);
    }

    public function test_export_filename_uses_the_configured_timezone_not_utc(): void
    {
        $owner = User::factory()->create(['level' => 'admin', 'is_active' => true]);
        $this->actingAs($owner);
        Library::query()->create(['name' => 'Novels', 'media_type' => 'book', 'owner_id' => $owner->id]);
        Carbon::setTestNow(Carbon::parse('2026-08-13 01:30:00', 'UTC'));
        SystemSetting::set('timezone', 'Europe/Berlin');

        $response = $this->postJson('/api/admin/export', []);

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('20260813-0330', $disposition);
        $this->assertStringNotContainsString('20260813-0130', $disposition);
    }
}
