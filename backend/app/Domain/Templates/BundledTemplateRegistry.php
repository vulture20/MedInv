<?php

namespace App\Domain\Templates;

use App\Models\Template;
use Illuminate\Support\Facades\Log;

/**
 * UI templates bundled with the repository/image itself — plain JSON files
 * under templates/ at the project root (config('medinv.templates_path'),
 * see config/medinv.php for why it lives there and how the path resolves
 * identically in dev and Docker), each shaped like what
 * TemplateController::show() already returns for one template:
 * {code, name, colors}. Deliberate structural mirror of
 * BundledLanguagePackRegistry — see that class's docblock for the shared
 * reasoning (plain JSON for easy hand-editing, a bundled entry becomes a
 * completely ordinary database row once installed with no further
 * distinction from an admin-created one, reinstallable from here after
 * deletion).
 */
class BundledTemplateRegistry
{
    /** @return array<int, array{code: string, name: string, colors: array}> */
    public function readAll(): array
    {
        $dir = config('medinv.templates_path');
        if (! is_dir($dir)) {
            return [];
        }

        $templates = [];
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $data = json_decode(file_get_contents($file), true);

            // Defensive, not just paranoid: this runs on every boot via
            // installMissing() (DatabaseSeeder, docker/entrypoint.sh's
            // `db:seed --force`) — a single malformed bundled file must not
            // break every fresh install and restart, same reasoning as
            // BundledLanguagePackRegistry's identical guard. Also checks
            // every REQUIRED_COLOR_KEYS entry is present, not just that
            // `colors` is non-empty — the language-pack equivalent doesn't
            // need this (a partial translations object degrades gracefully
            // via i18next's fallback), but a template missing a color just
            // leaves that one UI element unstyled.
            $missingColorKeys = is_array($data['colors'] ?? null)
                ? array_diff(Template::REQUIRED_COLOR_KEYS, array_keys($data['colors']))
                : Template::REQUIRED_COLOR_KEYS;

            if (json_last_error() !== JSON_ERROR_NONE || empty($data['code']) || empty($data['name']) || $missingColorKeys !== []) {
                Log::warning('Skipping malformed bundled template file', ['file' => basename($file), 'missing_color_keys' => $missingColorKeys]);

                continue;
            }

            $templates[] = $data;
        }

        return $templates;
    }

    /** @return array<int, array{code: string, name: string}> Lightweight listing for the admin UI — no colors blob. */
    public function available(): array
    {
        return array_map(
            fn (array $template) => ['code' => $template['code'], 'name' => $template['name']],
            $this->readAll(),
        );
    }

    /**
     * Installs every bundled template that doesn't already have a database
     * row — firstOrCreate, so it never overwrites a row an admin has since
     * edited. Called from DatabaseSeeder on every boot (db:seed --force),
     * mirroring MetadataProviderRegistry::syncToDatabase()'s identical
     * self-healing reasoning (GitHub issue #17): a fresh install has every
     * bundled template pre-installed from the start, and an existing
     * deployment picks up any new bundled template shipped in a later image
     * update on its next restart, without a separate migration step.
     */
    public function installMissing(): void
    {
        foreach ($this->readAll() as $template) {
            Template::query()->firstOrCreate(
                ['code' => $template['code']],
                ['name' => $template['name'], 'colors' => $template['colors']],
            );
        }
    }

    /**
     * Installs (or reinstalls) exactly one bundled template by code, always
     * overwriting name/colors — unlike installMissing()'s boot-time self-
     * heal, this backs a deliberate admin action
     * (TemplateController::installBundled()), so it's allowed to reset a
     * template an admin had since edited back to the shipped default.
     * Returns null if no bundled file matches $code (never actually
     * reachable from the admin UI, which only ever offers codes from
     * available()).
     */
    public function install(string $code): ?Template
    {
        $template = collect($this->readAll())->firstWhere('code', $code);
        if (! $template) {
            return null;
        }

        return Template::query()->updateOrCreate(
            ['code' => $template['code']],
            ['name' => $template['name'], 'colors' => $template['colors']],
        );
    }
}
