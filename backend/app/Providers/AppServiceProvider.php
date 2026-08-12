<?php

namespace App\Providers;

use App\Models\SystemSetting;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * Applies admin-configured settings (system_settings) onto Laravel's
     * live config on every request — see applyLogLevel()/applyMailConfig()
     * below — instead of only whatever was baked in at container boot via
     * MEDINV_* env vars. Wrapped in try/catch because this runs before the
     * system_settings table is guaranteed to exist yet (e.g. during the
     * very first `migrate`).
     */
    public function boot(): void
    {
        try {
            if (! SystemSetting::query()->getConnection()->getSchemaBuilder()->hasTable((new SystemSetting)->getTable())) {
                return;
            }

            $this->applyLogLevel();
            $this->applyMailConfig();
        } catch (\Throwable) {
            // No DB connection yet (e.g. `artisan key:generate` before first migrate) — fall back to .env config.
        }
    }

    /**
     * Applies the admin-configured loglevel (briefing 15./16., stored in
     * system_settings via AdminSettingsController::updateLoglevel(), initial
     * value from MEDINV_LOGLEVEL) onto the actual log channel(s) — until
     * this existed, changing the loglevel in the admin UI updated the
     * stored value but never actually affected what Log::info() etc. wrote,
     * since config/logging.php's channel `level` is otherwise only ever set
     * from the LOG_LEVEL env var at container boot. Applied to both
     * `single` and `daily`, the two file-based channels MedInv ships with —
     * whichever one LOG_STACK/LOG_CHANNEL actually routes through picks it up.
     */
    private function applyLogLevel(): void
    {
        $level = strtolower(SystemSetting::get('loglevel', env('MEDINV_LOGLEVEL', 'WARNING')));

        config([
            'logging.channels.single.level' => $level,
            'logging.channels.daily.level' => $level,
        ]);
    }

    /**
     * Applies the admin-configured SMTP settings (briefing 12.2, stored in
     * system_settings via AdminSettingsController) onto Laravel's mail
     * config at runtime, so `Mail`/`Password::sendResetLink()` actually use
     * them instead of the .env-defined mailer.
     */
    private function applyMailConfig(): void
    {
        $host = SystemSetting::get('mail.host');

        if (! $host) {
            return;
        }

        $encryption = SystemSetting::get('mail.encryption', 'starttls');

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => SystemSetting::get('mail.port'),
            'mail.mailers.smtp.username' => SystemSetting::get('mail.username'),
            'mail.mailers.smtp.password' => SystemSetting::get('mail.password'),
            'mail.mailers.smtp.scheme' => $encryption === 'ssl_tls' ? 'smtps' : null,
            // 'none' (briefing 12.2) disables the opportunistic STARTTLS upgrade Symfony
            // Mailer otherwise attempts on a plain 'smtp' scheme — see EsmtpTransportFactory,
            // where auto_tls=false forces $tls=false instead of null (auto-negotiate).
            'mail.mailers.smtp.auto_tls' => $encryption !== 'none',
            'mail.from.address' => SystemSetting::get('mail.from_address'),
            'mail.from.name' => SystemSetting::get('mail.from_name'),
        ]);
    }
}
