<?php

namespace App\Providers;

use App\Models\SystemSetting;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * Applies the admin-configured SMTP settings (briefing 12.2, stored in
     * system_settings via AdminSettingsController) onto Laravel's mail
     * config at runtime, so `Mail`/`Password::sendResetLink()` actually use
     * them instead of the .env-defined mailer. Wrapped in try/catch because
     * this runs on every request before the system_settings table is
     * guaranteed to exist yet (e.g. during the very first `migrate`).
     */
    public function boot(): void
    {
        try {
            if (! SystemSetting::query()->getConnection()->getSchemaBuilder()->hasTable((new SystemSetting)->getTable())) {
                return;
            }

            $host = SystemSetting::get('mail.host');

            if (! $host) {
                return;
            }

            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => $host,
                'mail.mailers.smtp.port' => SystemSetting::get('mail.port'),
                'mail.mailers.smtp.username' => SystemSetting::get('mail.username'),
                'mail.mailers.smtp.password' => SystemSetting::get('mail.password'),
                'mail.mailers.smtp.scheme' => SystemSetting::get('mail.encryption') === 'ssl_tls' ? 'smtps' : null,
                'mail.from.address' => SystemSetting::get('mail.from_address'),
                'mail.from.name' => SystemSetting::get('mail.from_name'),
            ]);
        } catch (\Throwable) {
            // No DB connection yet (e.g. `artisan key:generate` before first migrate) — fall back to .env mail config.
        }
    }
}
