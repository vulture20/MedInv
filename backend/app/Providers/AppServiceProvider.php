<?php

namespace App\Providers;

use App\Models\SystemSetting;
use Illuminate\Auth\Notifications\ResetPassword;
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
        // No DB dependency, so registered unconditionally (outside the try/catch
        // below) rather than only once system_settings is known to exist.
        $this->applyPasswordResetUrl();

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
     * Points the password-reset link (briefing 12.3) at the frontend SPA
     * instead of Laravel's own default. Without this,
     * ResetPassword::toMail()'s fallback URL-builder calls
     * route('password.reset', ..., false) — but no route by that name is
     * registered (the SPA, not Laravel, owns /password/reset; see
     * routes/web.php), so it throws RouteNotFoundException and
     * PasswordResetController::sendResetLink() 500s outright rather than
     * sending anything. config('app.url') is the same "public URL this
     * instance is reachable at" value docker/entrypoint.sh derives from
     * MEDINV_URL and UserInvitationMail already uses.
     */
    private function applyPasswordResetUrl(): void
    {
        ResetPassword::createUrlUsing(fn ($notifiable, string $token) => sprintf(
            '%s/password/reset?token=%s&email=%s',
            rtrim(config('app.url'), '/'),
            $token,
            urlencode($notifiable->getEmailForPasswordReset()),
        ));
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
