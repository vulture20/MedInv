<?php

namespace App\Http\Controllers\Api;

use App\Domain\Mail\MailStatusService;
use App\Http\Controllers\Controller;
use App\Models\LanguagePack;
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
            // GitHub issue #202: whether admins may edit an already-captured
            // item's EAN at all (GitHub issue #201's own admin-only editor,
            // MediaItemController::update()) — also mirrored onto GET /me as
            // ean_editing_enabled so any already-loaded page can gate the
            // editor's UI on it without an extra request, see
            // AuthController::me()'s own docblock.
            'ean_editing' => [
                'enabled' => SystemSetting::get('ean_editing.enabled', false),
            ],
            'loglevel' => SystemSetting::get('loglevel', env('MEDINV_LOGLEVEL', 'WARNING')),
            'locale' => [
                'default_language' => SystemSetting::get('locale.default_language', 'en'),
            ],
            'timezone' => SystemSetting::get('timezone', SystemSetting::defaultTimezone()),
            // GitHub issue #199: SystemSettingsPage.tsx's timezone <select>
            // used to source its own options from the browser's
            // Intl.supportedValuesOf('timeZone') — a different, independently
            // versioned tzdata/ICU build than whatever PHP was compiled
            // against, with no guaranteed parity between the two. Exposing
            // updateTimezone()'s own validation list here means the dropdown
            // can only ever offer a value the backend is guaranteed to
            // accept, the same "the server is the source of truth for its
            // own allowed values" shape updateLocale()'s $allowedCodes
            // already establishes for default_language.
            'timezone_options' => \DateTimeZone::listIdentifiers(),
            'statistics' => [
                'default_currency' => SystemSetting::get('statistics.default_currency'),
            ],
            'oidc' => [
                'enabled' => SystemSetting::get('oidc.enabled', false),
                'issuer' => SystemSetting::get('oidc.issuer'),
                'client_id' => SystemSetting::get('oidc.client_id'),
                // client_secret deliberately omitted — same "never echo a
                // stored secret back to the admin UI" policy as mail.password
                // above; OidcPage.tsx's secret field always starts empty.
                'provider_name' => SystemSetting::get('oidc.provider_name', 'Single Sign-On'),
                'auto_provision' => SystemSetting::get('oidc.auto_provision', false),
                'default_level' => SystemSetting::get('oidc.default_level', 'user'),
            ],
        ];
    }

    /**
     * System-settings-change audit trail (briefing 15.), previously not
     * logged at all — an admin changing mail/security/backup-schedule/etc.
     * configuration left no record beyond whatever the value happens to be
     * *now*, with no way to tell who changed it or when. $changes is logged
     * as-is except for a `password` key (updateMail()'s SMTP password) or a
     * `client_secret` key (updateOidc()'s OIDC client secret), neither of
     * which may ever reach the log in the clear.
     */
    private function logSettingsChange(Request $request, string $category, array $changes): void
    {
        foreach (['password', 'client_secret'] as $secretKey) {
            if (array_key_exists($secretKey, $changes) && $changes[$secretKey] !== null) {
                $changes[$secretKey] = '[REDACTED]';
            }
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

    /**
     * GitHub issue #202: lets an admin turn off GitHub issue #201's
     * admin-only EAN editor entirely, e.g. a deployment that wants manual
     * EAN correction kept out of reach even for admins. MediaItemController::
     * update() re-reads this same setting on every request rather than
     * trusting the frontend to have hidden the editor — a disabled admin
     * still gets exactly the pre-#201 "ean is silently dropped" behavior,
     * not an error.
     */
    public function updateEanEditing(Request $request)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        SystemSetting::set('ean_editing.enabled', $data['enabled']);
        $this->logSettingsChange($request, 'ean_editing', $data);

        return $this->index()['ean_editing'];
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
     * pre-login) via GET /locale, see routes/api.php. Accepts the two
     * bundled languages plus any language pack that currently has a
     * `language_packs` row — admin-added (LanguagePackController::store())
     * or one of the repo-shipped languagepacks/*.json packs
     * (BundledLanguagePackRegistry), it makes no difference here, since
     * both end up as the same kind of row. The allowed-codes list is
     * computed fresh on every request rather than cached, so a pack
     * deleted a moment ago can't still be picked (and a just-installed one
     * is immediately selectable) — this setting is small and rarely
     * changed, so the extra query per save is not worth optimizing away.
     */
    public function updateLocale(Request $request)
    {
        $allowedCodes = [...['de', 'en'], ...LanguagePack::query()->pluck('code')->all()];
        $data = $request->validate(['default_language' => ['required', Rule::in($allowedCodes)]]);

        SystemSetting::set('locale.default_language', $data['default_language']);
        $this->logSettingsChange($request, 'locale', $data);

        return response()->json(['default_language' => $data['default_language']]);
    }

    /**
     * GitHub issue #62 (a scoped-down alternative to a real per-currency
     * total, see StatisticsService::overviewFor()'s docblock): an ISO 4217
     * code (e.g. "USD"/"EUR") an admin declares as this instance's
     * expected currency, purely so a price/currency (#58) that disagrees
     * with it can be flagged — same "not validated against an actual
     * currency list" stance MediaItemController::rulesFor()'s own
     * `currency` rule already takes. `null` (the default, nothing saved
     * yet) deliberately means "not configured" rather than falling back to
     * some hardcoded currency — there's no universally sensible default,
     * and no mismatch should ever be flagged before an admin has actually
     * opted into this.
     */
    public function updateStatistics(Request $request)
    {
        $data = $request->validate(['default_currency' => ['nullable', 'string', 'max:3']]);

        SystemSetting::set('statistics.default_currency', $data['default_currency']);
        $this->logSettingsChange($request, 'statistics', $data);

        return response()->json(['default_currency' => $data['default_currency']]);
    }

    /**
     * GitHub issue #16. `client_secret` follows the exact same convention
     * as updateMail()'s `password`: never echoed back by index() (so the
     * field on OidcPage.tsx always starts empty), and left untouched here
     * when submitted empty — only actually stored when the admin types a
     * new one. `default_level` is restricted to guest/user at the
     * validation layer already, but OidcAuthController::resolveUser()
     * clamps it again independently rather than trusting that no other
     * path could ever write an unvalidated value into this setting.
     */
    public function updateOidc(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'issuer' => ['nullable', 'string', 'url'],
            'client_id' => ['nullable', 'string'],
            'client_secret' => ['nullable', 'string'],
            'provider_name' => ['nullable', 'string', 'max:255'],
            'auto_provision' => ['required', 'boolean'],
            'default_level' => ['required', Rule::in(['guest', 'user'])],
        ]);

        foreach ($data as $key => $value) {
            if ($key === 'client_secret' && ($value === null || $value === '')) {
                continue;
            }
            SystemSetting::set("oidc.{$key}", $value);
        }

        $this->logSettingsChange($request, 'oidc', $data);

        return $this->index()['oidc'];
    }
}
