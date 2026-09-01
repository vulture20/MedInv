<?php

/**
 * MedInv-specific configuration, kept separate from Laravel's own config/*
 * files. `version` is the single source of truth for the app version shown
 * in the UI (login screen, app footer) — see routes/api.php's public
 * `/api/version` endpoint and frontend/src/components/VersionBadge.tsx.
 * Bump it here on release.
 *
 * `frontend/package.json`'s own `version` field (unrelated to anything the
 * running app actually reads or displays — no code, build step, or Vite
 * `define` pulls it in) should still be bumped to match on the same
 * release, in npm's required plain-semver form (e.g. this file's "v0.5" ->
 * package.json's "0.5.0") rather than the "vX.Y" form used here — npm
 * itself requires `version` to be valid semver with no leading "v" and
 * conventionally three components. There's no automated check tying the
 * two together (a mismatch breaks nothing at runtime), so this is a
 * "please remember" note, not an enforced invariant.
 */
return [
    'name' => 'MedInv',
    'version' => 'v0.8',

    // Bundled language packs (briefing 11.4/17., GitHub issues #12/#15),
    // App\Domain\Languages\BundledLanguagePackRegistry — plain JSON files
    // under languagepacks/ at the project root, a sibling of backend/ (not
    // nested inside it) so they're easy to find/edit without digging into
    // the backend tree. Kept at exactly the same relative position inside
    // the Docker image (docker/Dockerfile's dedicated COPY, alongside
    // `COPY backend/ ./`), so this one base_path() expression resolves
    // correctly in both local dev (backend/ run directly, project root one
    // level up) and the container (WORKDIR /var/www/backend, project root
    // equivalent at /var/www) without needing an environment-specific branch.
    'languagepacks_path' => base_path('../languagepacks'),

    // Bundled UI templates (briefing 10./11.4, GitHub issue #11),
    // App\Domain\Templates\BundledTemplateRegistry — same reasoning and the
    // same relative-position trick as languagepacks_path above: a sibling
    // of backend/ at the project root, both locally and inside the Docker
    // image (docker/Dockerfile's dedicated COPY).
    'templates_path' => base_path('../templates'),

    // Bundled UI locale files (frontend/src/i18n/locales/{de,en}.json,
    // briefing 11.4/GitHub issue #113) — App\Domain\Languages\Translator's
    // source for the two bundled languages (every other language lives in
    // the language_packs table instead, see BundledLanguagePackRegistry).
    // Unlike languagepacks_path/templates_path above, this isn't a
    // top-level sibling of backend/ in the repo — it's nested inside
    // frontend/src/i18n/locales/ — so the same base_path('../X') trick
    // needs docker/Dockerfile to COPY it to that exact same nested path
    // under WORKDIR's parent (rather than flattening it to a new top-level
    // directory) for this one expression to resolve identically in local
    // dev and inside the container.
    'locales_path' => base_path('../frontend/src/i18n/locales'),
];
