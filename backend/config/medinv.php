<?php

/**
 * MedInv-specific configuration, kept separate from Laravel's own config/*
 * files. `version` is the single source of truth for the app version shown
 * in the UI (login screen, app footer) — see routes/api.php's public
 * `/api/version` endpoint and frontend/src/components/VersionBadge.tsx.
 * Bump it here on release; nothing else needs to change.
 */
return [
    'name' => 'MedInv',
    'version' => 'v0.5',

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
];
