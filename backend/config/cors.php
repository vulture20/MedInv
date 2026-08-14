<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    // Sanctum SPA auth uses cookies, which requires supports_credentials=true
    // below — and browsers reject a wildcard allowed_origins whenever
    // credentials are involved, so FRONTEND_URL must name the SPA explicitly
    // (kept in sync with sanctum.stateful in config/sanctum.php).
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Content-Disposition needs to be readable by JS for the export download
    // (ExportImportPage.tsx POSTs a library selection and reads the
    // server-computed filename off the blob response — unlike backup
    // download, which is a plain GET <a href> the browser handles natively
    // without any JS needing the header at all). Same-origin production
    // deployments don't need this (no CORS involved), but cross-origin local
    // dev (5173 -> 8000) does, since browsers hide response headers from JS
    // on a cross-origin request unless the server explicitly exposes them.
    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => true,

];
