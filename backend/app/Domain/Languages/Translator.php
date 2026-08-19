<?php

namespace App\Domain\Languages;

use App\Models\LanguagePack;
use Illuminate\Support\Facades\Log;

/**
 * Server-side lookup against the exact same translation strings the
 * frontend already uses — GitHub issue #113 (PDF exports rendering
 * English-only regardless of the requesting user's `preferred_language`),
 * whose own investigation found that reuse was the whole point: every
 * string a PDF needs already has a translation in frontend/src/i18n/
 * locales/{en,de}.json (the two bundled languages) or, for every other
 * language, in the corresponding `language_packs` database row (installed
 * from languagepacks/*.json — see BundledLanguagePackRegistry). This class
 * is what makes that data reachable from PHP; nothing here duplicates or
 * re-translates anything, it only reads what already exists.
 *
 * `App\Domain\ExportPdf\PdfExportService` is this class's first and (as of
 * #113) only consumer — a generic Laravel `lang/`-based translator would
 * have been the "normal" choice, but this app deliberately keeps all UI
 * strings in the frontend's own i18n JSON (see CLAUDE.md), so reusing that
 * single source of truth needs this kind of lookup instead.
 */
class Translator
{
    /** @var array<string, array> in-request cache, one entry per resolved language code — a PDF export looks up dozens of keys against the same one or two catalogs. */
    private array $catalogs = [];

    /**
     * Dotted-path lookup (e.g. "reports.topLists.mostExpensive") with a
     * two-step fallback: the requested language, then English, then (if
     * even English doesn't have it — should never happen for a real key,
     * but a defensive last resort rather than a fatal error) the raw key
     * itself, so a lookup typo surfaces as visibly-wrong text in the PDF
     * instead of crashing the export entirely.
     */
    public function get(?string $languageCode, string $key, array $replace = []): string
    {
        $value = $this->lookup($this->catalogFor($languageCode), $key)
            ?? $this->lookup($this->catalogFor('en'), $key)
            ?? $key;

        return $this->interpolate($value, $replace);
    }

    /**
     * i18next-style pluralization, matching the `${key}_one`/`${key}_other`
     * convention every one of this app's translation files already uses —
     * confirmed (GitHub issue #113's own investigation, backed by
     * LanguagePackKeysInSyncTest's key-parity check) that even the bundled
     * Slavic packs (pl/ru/uk) only ever define these two forms here rather
     * than their language's full plural-form grammar, so `$count === 1` is
     * the one distinction this app's own translations ever encode; this
     * mirrors that, not a general-purpose CLDR plural rule engine. `count`
     * is merged into `$replace` automatically, mirroring i18next's own
     * behavior of exposing it to the string as `{{count}}`.
     */
    public function plural(?string $languageCode, string $key, int $count, array $replace = []): string
    {
        $suffix = $count === 1 ? '_one' : '_other';

        return $this->get($languageCode, $key.$suffix, [...$replace, 'count' => $count]);
    }

    /** @return array<string, mixed> the fully nested translations tree for one language code, cached per request. */
    private function catalogFor(?string $languageCode): array
    {
        $code = $languageCode ?: 'en';

        return $this->catalogs[$code] ??= $this->load($code);
    }

    /** @return array<string, mixed> */
    private function load(string $code): array
    {
        // The two bundled languages live as plain JSON files (see this
        // class's own docblock) — not `translations`-wrapped like a
        // language_packs row, so read directly.
        if (in_array($code, ['en', 'de'], true)) {
            $path = config('medinv.locales_path')."/{$code}.json";
            $data = is_file($path) ? json_decode(file_get_contents($path), true) : null;

            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return $data;
            }

            Log::warning('Could not read bundled locale file', ['code' => $code, 'path' => $path]);

            // 'en' failing to load is the true worst case (nothing left to
            // fall back to below) — every other path in this method
            // ultimately falls back to English, so this one has to return
            // something rather than recurse forever.
            return $code === 'en' ? [] : $this->load('en');
        }

        $translations = LanguagePack::query()->where('code', $code)->value('translations');

        // Covers both an unknown code (never installed) and a real one an
        // admin has since deleted — either way, falling back to English
        // beats failing the export outright over a UI-language setting a
        // user picked at some point in the past.
        return is_array($translations) ? $translations : $this->load('en');
    }

    private function lookup(array $catalog, string $key): ?string
    {
        $value = $catalog;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return is_string($value) ? $value : null;
    }

    /** {{token}} interpolation, matching the exact syntax these same JSON files already use for i18next on the frontend. */
    private function interpolate(string $value, array $replace): string
    {
        foreach ($replace as $token => $replacement) {
            $value = str_replace('{{'.$token.'}}', (string) $replacement, $value);
        }

        return $value;
    }
}
