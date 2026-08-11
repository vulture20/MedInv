<?php

use Illuminate\Support\Facades\Route;

// The React SPA (../frontend) is the actual UI and is served separately —
// by Vite in development, and by nginx in the single-container Docker
// deployment (see docker/nginx.conf), which routes only /api and /sanctum
// to this Laravel app. This root route only exists so hitting the backend
// directly (e.g. `php artisan serve` on its own) doesn't 404.
Route::get('/', fn () => response()->json(['name' => 'MedInv API', 'docs' => '/api']));
