<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Flat key-value system configuration store (briefing 15.). Prefer
 * SystemSetting::get()/set() over direct queries so reads are cached.
 */
#[Fillable(['key', 'value'])]
class SystemSetting extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("system_setting:{$key}", function () use ($key, $default) {
            return static::query()->where('key', $key)->value('value') ?? $default;
        });
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("system_setting:{$key}");
    }

    /**
     * The defaults every {@see get()} caller across the app falls back to
     * when a key was never explicitly saved — kept here as the single
     * source of truth (rather than scattered as ad hoc `get($key, $default)`
     * calls in AdminSettingsController and elsewhere) specifically so
     * {@see allAsArray()} can guarantee a backup/export always carries every
     * system setting, not just the ones an admin happened to touch.
     */
    public static function defaults(): array
    {
        return [
            'mail.encryption' => 'starttls',
            'backup.interval_mode' => 'daily',
            'security.throttle_max_attempts' => 6,
            'security.throttle_window_minutes' => 5,
            'security.throttle_lock_minutes' => 30,
            'covers.cleanup_enabled' => true,
            'timezone' => static::defaultTimezone(),
            'loglevel' => env('MEDINV_LOGLEVEL', 'WARNING'),
            'locale.default_language' => 'en',
        ];
    }

    /**
     * Falls back to the deployer-provided `TZ` environment variable — the
     * near-universal Docker convention for timezone-aware containers (e.g.
     * Sonarr/Radarr/Immich) — for as long as no admin has explicitly saved
     * a `timezone` setting via AdminSettingsController::updateTimezone().
     * Deliberately unprefixed, unlike every other env var this app reads
     * (see CLAUDE.md's "All environment variables are MEDINV_-prefixed"):
     * `TZ` is a cross-application OS-level standard, not something
     * specific to MedInv, and deployers already widely expect it to work
     * this way. PHP itself does *not* read `TZ` automatically for
     * date_default_timezone_get() (confirmed live — that legacy fallback
     * no longer exists), and the shipped Alpine image has no `tzdata`
     * package at all (no /etc/localtime or /etc/timezone to fall back to
     * either), so without this, "TZ" set by a deployer was silently
     * ignored. Validated against \DateTimeZone::listIdentifiers() — the
     * same set AdminSettingsController::updateTimezone() itself validates
     * against — so a typo'd or region-less TZ value (e.g. Docker's own
     * "Etc/UTC" is valid, but something malformed isn't) can never reach
     * localNow()'s setTimezone() call, which throws on an unrecognized
     * identifier.
     */
    public static function defaultTimezone(): string
    {
        $tz = env('TZ');

        return $tz && in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'UTC';
    }

    /**
     * All settings as a flat key => value array, for inclusion in
     * exports/backups (see ExportImportService::exportLibraries()). Named
     * `allAsArray` rather than overriding Eloquent's static `all()`, which
     * returns a Collection of models, not the key-value shape callers here
     * actually want. Merges in {@see defaults()} first so a fresh instance
     * whose admin never opened e.g. the security or backup-schedule form
     * still backs up its actually-effective settings, not just the rows
     * that happen to exist in the table.
     */
    public static function allAsArray(): array
    {
        return [...static::defaults(), ...static::query()->pluck('value', 'key')->all()];
    }

    /**
     * Current time in the admin-configured display timezone (`timezone`
     * setting, GitHub issue #31) — for filenames and other text a human
     * directly reads (BackupService's backup filename,
     * ExportImportController's export filename), not for anything stored
     * or compared internally.
     *
     * Deliberately does NOT touch `config('app.timezone')` or PHP's global
     * default timezone: `created_at`/`updated_at` columns are stored as
     * naive datetimes with no timezone of their own, so changing the
     * global default after records already exist would silently
     * reinterpret old timestamps in the new zone while new ones use it
     * going forward — the two would no longer agree on what "the same
     * clock time" means. It would also shift exactly when the scheduled
     * backup/cover-cleanup cron expressions in routes/console.php are
     * considered due. `localNow()` only ever affects freshly-generated,
     * human-facing text, never anything the app itself stores or reasons
     * about.
     */
    public static function localNow(): Carbon
    {
        return now()->setTimezone(static::get('timezone', static::defaultTimezone()));
    }
}
