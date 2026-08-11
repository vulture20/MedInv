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
];
