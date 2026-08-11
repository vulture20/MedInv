<?php

namespace App\Http\Controllers\Api;

use App\Domain\Mail\MailStatusService;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
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
                'retention_count' => SystemSetting::get('backup.retention_count'),
                'retention_max_age_days' => SystemSetting::get('backup.retention_max_age_days'),
            ],
            'security' => [
                'throttle_max_attempts' => SystemSetting::get('security.throttle_max_attempts', 6),
                'throttle_window_minutes' => SystemSetting::get('security.throttle_window_minutes', 5),
                'throttle_lock_minutes' => SystemSetting::get('security.throttle_lock_minutes', 30),
            ],
            'loglevel' => SystemSetting::get('loglevel', env('MEDINV_LOGLEVEL', 'INFO')),
        ];
    }

    public function updateMail(Request $request)
    {
        $data = $request->validate([
            'host' => ['required', 'string'],
            'port' => ['required', 'integer'],
            'username' => ['nullable', 'string'],
            'password' => ['nullable', 'string'],
            'encryption' => ['required', Rule::in(['ssl_tls', 'starttls'])],
            'from_address' => ['required', 'email'],
            'from_name' => ['required', 'string'],
        ]);

        foreach ($data as $key => $value) {
            SystemSetting::set("mail.{$key}", $value);
        }

        return response()->json(['healthy' => $this->mailStatus->isHealthy()]);
    }

    public function updateBackup(Request $request)
    {
        $data = $request->validate([
            'interval_mode' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'cron'])],
            'cron_expression' => ['required_if:interval_mode,cron', 'nullable', 'string'],
            'retention_count' => ['nullable', 'integer', 'min:1'],
            'retention_max_age_days' => ['nullable', 'integer', 'min:1'],
        ]);

        foreach ($data as $key => $value) {
            SystemSetting::set("backup.{$key}", $value);
        }

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

        return $this->index()['security'];
    }

    public function updateLoglevel(Request $request)
    {
        $data = $request->validate(['loglevel' => ['required', Rule::in(['DEBUG', 'INFO', 'WARNING', 'ERROR'])]]);

        SystemSetting::set('loglevel', $data['loglevel']);

        return response()->json(['loglevel' => $data['loglevel']]);
    }
}
