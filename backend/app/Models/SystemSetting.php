<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
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
            'loglevel' => env('MEDINV_LOGLEVEL', 'WARNING'),
            'locale.default_language' => 'en',
        ];
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
}
