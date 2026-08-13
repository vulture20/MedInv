<?php

use App\Http\Controllers\Api\AccountSettingsController;
use App\Http\Controllers\Api\AdminSettingsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\CaptureController;
use App\Http\Controllers\Api\ExportImportController;
use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\MediaItemController;
use App\Http\Controllers\Api\MetadataController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\StatisticsController;
use App\Http\Controllers\Api\UserController;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Route;

// Public (briefing 11.1: login screen is the only thing reachable unauthenticated).
Route::post('/login', [AuthController::class, 'login']);
Route::post('/password/email', [PasswordResetController::class, 'sendResetLink']);
Route::post('/password/reset', [PasswordResetController::class, 'reset']);

// Shown on the login screen (unauthenticated) and in the app footer alike —
// single source of truth is config/medinv.php.
Route::get('/version', fn () => response()->json([
    'name' => config('medinv.name'),
    'version' => config('medinv.version'),
]));

// Also needed before login: a visitor whose browser language is neither German nor
// English falls back to this admin-configured default (briefing 11.4) instead of a
// hardcoded 'en' — see frontend/src/i18n/index.ts's applyAdminDefaultLanguage().
// Deliberately its own tiny route rather than folded into /version above, which is
// documented as sourced solely from config/medinv.php, not the system_settings table.
Route::get('/locale', fn () => response()->json([
    'default_language' => SystemSetting::get('locale.default_language', 'en'),
]));

// Sanctum SPA session auth (bootstrap/app.php: ->statefulApi()).
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me/settings', [AccountSettingsController::class, 'update']);

    // Search & statistics — available to guest/user/admin alike, each scoped
    // to what LibraryAccessService says they can read (4.3, 13., 14.).
    Route::get('/search', SearchController::class);
    Route::get('/statistics', StatisticsController::class);

    // Libraries — readable per LibraryAccessService; write endpoints re-check
    // ownership/admin inside the controller (5., 4.3). Guests never reach
    // store/update/destroy because they only get level 'guest'; the model
    // binding + in-controller check is what actually enforces it.
    Route::get('/libraries', [LibraryController::class, 'index']);
    Route::get('/libraries/{library}', [LibraryController::class, 'show']);
    Route::middleware('level:user,admin')->group(function () {
        Route::post('/libraries', [LibraryController::class, 'store']);
        Route::put('/libraries/{library}', [LibraryController::class, 'update']);
        Route::delete('/libraries/{library}', [LibraryController::class, 'destroy']);
        Route::put('/libraries/{library}/shares', [LibraryController::class, 'updateShares']);

        // Capture / media items — write access re-checked per-library inside
        // the controllers (owner or admin), see 7. and 6.
        Route::get('/libraries/{library}/items', [MediaItemController::class, 'index']);
        Route::get('/libraries/{library}/items/{item}', [MediaItemController::class, 'show']);
        Route::get('/libraries/{library}/items/{item}/cover', [MediaItemController::class, 'cover']);
        Route::post('/libraries/{library}/items', [MediaItemController::class, 'store']);
        Route::put('/libraries/{library}/items/{item}', [MediaItemController::class, 'update']);
        Route::delete('/libraries/{library}/items/{item}', [MediaItemController::class, 'destroy']);

        Route::post('/libraries/{library}/capture/scan', [CaptureController::class, 'scan']);
        Route::post('/libraries/{library}/capture/textfile', [CaptureController::class, 'textFile']);

        Route::get('/libraries/{library}/metadata/search', [MetadataController::class, 'search']);
        Route::post('/libraries/{library}/metadata/import', [MetadataController::class, 'import']);
    });
    Route::get('/metadata/plugins', [MetadataController::class, 'plugins']);

    // Administration (briefing 15.) — admin only.
    Route::middleware('level:admin')->prefix('admin')->group(function () {
        Route::apiResource('users', UserController::class)->except(['show']);
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);
        Route::post('/users/{user}/reactivate', [UserController::class, 'reactivate']);

        Route::put('/metadata/plugins/{plugin}', [MetadataController::class, 'updatePlugin']);

        Route::post('/export', [ExportImportController::class, 'export']);
        Route::post('/import', [ExportImportController::class, 'import']);

        Route::get('/backups', [BackupController::class, 'index']);
        Route::post('/backups', [BackupController::class, 'store']);
        Route::get('/backups/{backup}/download', [BackupController::class, 'download']);
        Route::delete('/backups/{backup}', [BackupController::class, 'destroy']);
        Route::post('/backups/{backup}/restore', [BackupController::class, 'restore']);

        Route::get('/settings', [AdminSettingsController::class, 'index']);
        Route::put('/settings/mail', [AdminSettingsController::class, 'updateMail']);
        Route::post('/settings/mail/test', [AdminSettingsController::class, 'testMail']);
        Route::put('/settings/backup', [AdminSettingsController::class, 'updateBackup']);
        Route::put('/settings/security', [AdminSettingsController::class, 'updateSecurity']);
        Route::put('/settings/loglevel', [AdminSettingsController::class, 'updateLoglevel']);
        Route::put('/settings/locale', [AdminSettingsController::class, 'updateLocale']);
    });
});
