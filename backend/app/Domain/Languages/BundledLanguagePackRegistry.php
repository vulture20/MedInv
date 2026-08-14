<?php

namespace App\Domain\Languages;

use App\Models\LanguagePack;
use Illuminate\Support\Facades\Log;

/**
 * Language packs bundled with the repository/image itself — plain JSON
 * files under languagepacks/ at the project root (config('medinv.
 * languagepacks_path'), see config/medinv.php for why it lives there and
 * how the path resolves identically in dev and Docker), each shaped like
 * what LanguagePackController::show() already returns for one pack:
 * {code, name, translations}. Deliberately plain JSON rather than PHP so
 * they're easy to inspect, hand-edit, or add to without touching code —
 * the same reasoning as locales/de.json's own format (briefing 11.4).
 *
 * A bundled pack becomes a completely ordinary `language_packs` database
 * row once installed — there's no further distinction from an admin-typed
 * one afterwards. Deleting it via the admin UI just removes that row, same
 * as any other pack; it can always be reinstalled from here.
 */
class BundledLanguagePackRegistry
{
    /** @return array<int, array{code: string, name: string, translations: array}> */
    public function readAll(): array
    {
        $dir = config('medinv.languagepacks_path');
        if (! is_dir($dir)) {
            return [];
        }

        $packs = [];
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $data = json_decode(file_get_contents($file), true);

            // Defensive, not just paranoid: this runs on every boot via
            // installMissing() (DatabaseSeeder, docker/entrypoint.sh's
            // `db:seed --force`) — a single malformed bundled file must not
            // break every fresh install and restart, the same reasoning as
            // the pg_trgm migration's own try/catch guards.
            if (json_last_error() !== JSON_ERROR_NONE || empty($data['code']) || empty($data['name']) || empty($data['translations'])) {
                Log::warning('Skipping malformed bundled language pack file', ['file' => basename($file)]);

                continue;
            }

            $packs[] = $data;
        }

        return $packs;
    }

    /** @return array<int, array{code: string, name: string}> Lightweight listing for the admin UI — no translations blob. */
    public function available(): array
    {
        return array_map(
            fn (array $pack) => ['code' => $pack['code'], 'name' => $pack['name']],
            $this->readAll(),
        );
    }

    /**
     * Installs every bundled pack that doesn't already have a database row
     * — firstOrCreate, so it never overwrites a row an admin has since
     * edited. Called from DatabaseSeeder on every boot (db:seed --force),
     * mirroring MetadataProviderRegistry::syncToDatabase()'s identical
     * self-healing reasoning (GitHub issue #17): a fresh install has every
     * bundled pack pre-installed from the start, and an existing
     * deployment picks up any new bundled packs shipped in a later image
     * update on its next restart, without a separate migration step.
     */
    public function installMissing(): void
    {
        foreach ($this->readAll() as $pack) {
            LanguagePack::query()->firstOrCreate(
                ['code' => $pack['code']],
                ['name' => $pack['name'], 'translations' => $pack['translations']],
            );
        }
    }

    /**
     * Installs (or reinstalls) exactly one bundled pack by code, always
     * overwriting name/translations — unlike installMissing()'s boot-time
     * self-heal, this backs a deliberate admin action
     * (LanguagePackController::installBundled()), so it's allowed to reset
     * a pack an admin had since edited back to the shipped default. Returns
     * null if no bundled file matches $code (never actually reachable from
     * the admin UI, which only ever offers codes from available()).
     */
    public function install(string $code): ?LanguagePack
    {
        $pack = collect($this->readAll())->firstWhere('code', $code);
        if (! $pack) {
            return null;
        }

        return LanguagePack::query()->updateOrCreate(
            ['code' => $pack['code']],
            ['name' => $pack['name'], 'translations' => $pack['translations']],
        );
    }
}
