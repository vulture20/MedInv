<?php

namespace App\Http\Controllers\Api;

use App\Domain\Mail\MailStatusService;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Central system configuration (briefing 15.): mail server (12.2), backup
 * interval/retention (9.2), brute-force thresholds (12.4), loglevel.
 * Everything here is stored in system_settings and editable at runtime —
 * only the bootstrap-time values in briefing chapter 16 come from
 * MEDINV_* environment variables instead. Admin-only, see routes/api.php.
 */
class AdminSettingsController extends Controller
{
    public function __construct(private readonly MailStatusService $mailStatus) {}

    public function index()
    {
        return [
            'mail' => [
                'host' => SystemSetting::get('mail.host'),
                'port' => SystemSetting::get('mail.port'),
                'username' => SystemSetting::get('mail.username'),
                'encryption' => SystemSetting::get('mail.encryption', 'starttls'),
                'from_address' => SystemSetting::get('mail.from_address'),
                'from_name' => SystemSetting::get('mail.from_name'),
                'healthy' => $this->mailStatus->isHealthy(),
            ],
            'backup' => [
                'interval_mode' => SystemSetting::get('backup.interval_mode', 'daily'),
                'cron_expression' => SystemSetting::get('backup.cron_expression'),
                'retention_mode' => SystemSetting::get('backup.retention_mode', 'count'),
                'retention_count' => SystemSetting::get('backup.retention_count'),
                'retention_max_age_days' => SystemSetting::get('backup.retention_max_age_days'),
            ],
            'security' => [
                'throttle_max_attempts' => SystemSetting::get('security.throttle_max_attempts', 6),
                'throttle_window_minutes' => SystemSetting::get('security.throttle_window_minutes', 5),
                'throttle_lock_minutes' => SystemSetting::get('security.throttle_lock_minutes', 30),
            ],
            'covers' => [
                'cleanup_enabled' => SystemSetting::get('covers.cleanup_enabled', true),
            ],
            'loglevel' => SystemSetting::get('loglevel', env('MEDINV_LOGLEVEL', 'WARNING')),
            'locale' => [
                'default_language' => SystemSetting::get('locale.default_language', 'en'),
            ],
            'timezone' => SystemSetting::get('timezone', SystemSetting::defaultTimezone()),
        ];
    }

    /**
     * System-settings-change audit trail (briefing 15.), previously not
     * logged at all — an admin changing mail/security/backup-schedule/etc.
     * configuration left no record beyond whatever the value happens to be
     * *now*, with no way to tell who changed it or when. $changes is logged
     * as-is except for a `password` key (updateMail()'s SMTP password),
     * which must never reach the log in the clear.
     */
    private function logSettingsChange(Request $request, string $category, array $changes): void
    {
        if (array_key_exists('password', $changes) && $changes['password'] !== null) {
            $changes['password'] = '[REDACTED]';
        }

        Log::info('Settings updated', ['actor_id' => $request->user()->id, 'category' => $category, 'changes' => $changes]);
    }

    public function updateMail(Request $request)
    {
        $data = $request->validate([
            'host' => ['required', 'string'],
            'port' => ['required', 'integer'],
            'username' => ['nullable', 'string'],
            'password' => ['nullable', 'string'],
            'encryption' => ['required', Rule::in(['ssl_tls', 'starttls', 'none'])],
            'from_address' => ['required', 'email'],
            'from_name' => ['required', 'string'],
        ]);

        foreach ($data as $key => $value) {
            SystemSetting::set("mail.{$key}", $value);
        }

        $this->logSettingsChange($request, 'mail', $data);

        return response()->json(['healthy' => $this->mailStatus->isHealthy()]);
    }

    /**
     * Sends a real test message through the currently *saved* mail
     * configuration (briefing 12.2) so an admin can verify it end-to-end
     * instead of only checking the reachability probe — a successful TCP
     * handshake (isHealthy()) doesn't guarantee auth/from-address/relay
     * rules are actually correct. Failures are surfaced with an error_code
     * and logged with the client IP (Controller::logApiError()) rather than
     * only the raw SMTP exception text.
     */
    public function testMail(Request $request)
    {
        $data = $request->validate(['to' => ['required', 'email']]);

        if (! $this->mailStatus->isConfigured()) {
            return $this->mailError($request, 'not_configured', 'The mail server is not configured yet.');
        }

        try {
            Mail::raw(
                "This is a test message from MedInv, sent to verify the configured mail server.\n\nIf you received this, outgoing mail is working correctly.",
                fn ($message) => $message->to($data['to'])->subject('MedInv — test message'),
            );
        } catch (\Throwable $e) {
            return $this->mailError($request, 'mail_test_failed', $e->getMessage());
        }

        return response()->json(['sent' => true]);
    }

    private function mailError(Request $request, string $code, string $message): JsonResponse
    {
        $this->logApiError($request, $code, $message, 'error');

        return response()->json(['error_code' => $code, 'message' => $message], 422);
    }

    public function updateBackup(Request $request)
    {
        $data = $request->validate([
            'interval_mode' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'cron'])],
            'cron_expression' => ['required_if:interval_mode,cron', 'nullable', 'string'],
            // 'count'/'age' picks which one of the two fields below BackupService::
            // prune() actually applies — see that method's docblock for why only one
            // is ever active, not both at once despite briefing 9.2's literal wording.
            'retention_mode' => ['required', Rule::in(['count', 'age'])],
            'retention_count' => ['nullable', 'integer', 'min:1'],
            'retention_max_age_days' => ['nullable', 'integer', 'min:1'],
        ]);

        foreach ($data as $key => $value) {
            SystemSetting::set("backup.{$key}", $value);
        }

        $this->logSettingsChange($request, 'backup', $data);

        return $this->index()['backup'];
    }

    public function updateSecurity(Request $request)
    {
        $data = $request->validate([
            'throttle_max_attempts' => ['required', 'integer', 'min:1'],
            'throttle_window_minutes' => ['required', 'integer', 'min:1'],
            'throttle_lock_minutes' => ['required', 'integer', 'min:1'],
        ]);

        foreach ($data as $key => $value) {
            SystemSetting::set("security.{$key}", $value);
        }

        $this->logSettingsChange($request, 'security', $data);

        return $this->index()['security'];
    }

    /** Toggles the daily orphaned-cover-file cleanup (CoverCleanupService, routes/console.php's `medinv-cover-cleanup` schedule). Default is enabled; disabling only affects that scheduled run, not `php artisan medinv:cleanup-covers` invoked by hand. */
    public function updateCoverCleanup(Request $request)
    {
        $data = $request->validate(['cleanup_enabled' => ['required', 'boolean']]);

        SystemSetting::set('covers.cleanup_enabled', $data['cleanup_enabled']);
        $this->logSettingsChange($request, 'covers', $data);

        return $this->index()['covers'];
    }

    public function updateLoglevel(Request $request)
    {
        $data = $request->validate(['loglevel' => ['required', Rule::in(['DEBUG', 'INFO', 'WARNING', 'ERROR'])]]);

        SystemSetting::set('loglevel', $data['loglevel']);
        $this->logSettingsChange($request, 'loglevel', $data);

        return response()->json(['loglevel' => $data['loglevel']]);
    }

    /**
     * The display timezone (GitHub issue #31) used only for filenames/text
     * a human directly reads (SystemSetting::localNow()) — not applied to
     * config('app.timezone')/PHP's default timezone at all, see that
     * method's docblock for why. Validated against PHP's own list of
     * recognized IANA identifiers rather than a hand-maintained enum, so
     * it never drifts out of sync with what `DateTimeZone` actually
     * accepts.
     */
    public function updateTimezone(Request $request)
    {
        $data = $request->validate(['timezone' => ['required', 'string', Rule::in(\DateTimeZone::listIdentifiers())]]);

        SystemSetting::set('timezone', $data['timezone']);
        $this->logSettingsChange($request, 'timezone', $data);

        return response()->json(['timezone' => $data['timezone']]);
    }

    /**
     * The language a visitor's browser falls back to when it declares
     * neither German nor English (briefing 11.4) — read publicly (even
     * pre-login) via GET /locale, see routes/api.php. Restricted to the two
     * shipped languages for now, same as AccountSettingsController's own
     * per-user preferred_language selector; once admin-managed language
     * packs exist (GitHub issues #12/#15) this may need to accept those
     * codes too.
     */
    public function updateLocale(Request $request)
    {
        $data = $request->validate(['default_language' => ['required', Rule::in(['de', 'en'])]]);

        SystemSetting::set('locale.default_language', $data['default_language']);
        $this->logSettingsChange($request, 'locale', $data);

        return response()->json(['default_language' => $data['default_language']]);
    }
}
