<?php

use App\Http\Controllers\Api\AccountSettingsController;
use App\Http\Controllers\Api\AdminSettingsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\CaptureController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExportImportController;
use App\Http\Controllers\Api\LanguagePackController;
use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\LibraryPreferenceController;
use App\Http\Controllers\Api\MediaItemController;
use App\Http\Controllers\Api\MetadataController;
use App\Http\Controllers\Api\OidcAuthController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\SavedSearchController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\StatisticsController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\UserController;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

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

// Also needed before login: a visitor whose browser language matches none of the
// installed languages (bundled or runtime pack) falls back to this admin-configured
// default (briefing 11.4) instead of a hardcoded 'en' — see frontend/src/i18n/
// index.ts's applyBrowserOrDefaultLanguage(). Deliberately its own tiny route rather
// than folded into /version above, which is documented as sourced solely from
// config/medinv.php, not the system_settings table.
Route::get('/locale', fn () => response()->json([
    'default_language' => SystemSetting::get('locale.default_language', 'en'),
]));

// Admin-added language packs beyond bundled German/English (briefing
// 11.4/17., GitHub issue #12) — deliberately fully public like /version
// and /locale above, not merely outside the inner level:admin group the
// way GET /metadata/plugins used to sit before issue #37: a visitor's
// translations must be loadable on the login screen itself, before
// anyone is authenticated. The actual "only admins may add a language
// pack" enforcement this issue asks for is create()/update()/destroy()
// below, in the level:admin group.
Route::get('/languages', [LanguagePackController::class, 'index']);
Route::get('/languages/{languagePack}', [LanguagePackController::class, 'show']);

// Admin-added UI templates beyond the bundled light/dark (briefing 10./
// 11.4, GitHub issue #11) — same public reasoning as GET /languages above:
// a visitor's chosen template must be renderable on the login screen
// itself. The actual "only admins may add a template" enforcement is
// create()/update()/destroy() below, in the level:admin group.
Route::get('/templates', [TemplateController::class, 'index']);
Route::get('/templates/{template}', [TemplateController::class, 'show']);

// OpenID Connect login (GitHub issue #16) — additional to, not a
// replacement for, the password form above. /config is a plain JSON
// probe (public, like /version and /locale) so LoginPage.tsx knows
// whether to render the SSO button at all. /redirect and /callback are
// full-page browser navigations, not XHR — see OidcAuthController's own
// docblock for why they need the 'web' middleware group rather than
// this file's default statefulApi()-gated session handling, which the
// callback leg (Referer = the provider's domain) would fail.
//
// withoutMiddleware(EnsureFrontendRequestsAreStateful) is deliberate, not
// redundant with the 'web' group just added: statefulApi() (applied to
// every route in this file) conditionally prepends its own
// EncryptCookies+StartSession whenever a request's Origin/Referer happens
// to match sanctum.stateful — true for /redirect specifically, since it's
// a real navigation initiated from this app's own SPA. Without this
// exclusion, /redirect would get session-handling middleware applied
// *twice* (once from that conditional prepend, once from 'web' below),
// and a second EncryptCookies pass tries to decrypt a cookie value the
// first pass already decrypted in place — confirmed live via a real
// browser: the session written to mid-request silently never persisted,
// StartSession's second pass instead started a brand new one every time.
// Excluding Sanctum's conditional middleware here makes 'web' the *only*
// session mechanism for both routes, applied exactly once, regardless of
// which leg's Origin/Referer would or wouldn't have otherwise matched.
Route::get('/auth/oidc/config', [OidcAuthController::class, 'config']);
Route::middleware('web')
    ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
    ->group(function () {
        Route::get('/auth/oidc/redirect', [OidcAuthController::class, 'redirect']);
        Route::get('/auth/oidc/callback', [OidcAuthController::class, 'callback']);
    });

// Sanctum SPA session auth (bootstrap/app.php: ->statefulApi()).
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me/settings', [AccountSettingsController::class, 'update']);
    // Self-service password change (GitHub issue #174) — see
    // AccountSettingsController::updatePassword()'s docblock.
    Route::put('/me/password', [AccountSettingsController::class, 'updatePassword']);
    // Self-service account deletion (GitHub issue #86) — see
    // AccountSettingsController::destroy()'s docblock.
    Route::delete('/me', [AccountSettingsController::class, 'destroy']);

    // Startseite (briefing 11.2, GitHub issue #116) — a fresh, random
    // per-media-type selection across every visible library, feeding
    // DashboardPage.tsx's three cover carousels. Available to guest/user/
    // admin alike, same visibility scoping as search/statistics below.
    Route::get('/dashboard/random-items', [DashboardController::class, 'randomItems']);

    // Search & statistics — available to guest/user/admin alike, each scoped
    // to what LibraryAccessService says they can read (4.3, 13., 14.).
    Route::get('/search', [SearchController::class, 'search']);
    // GitHub issue #73 — the values SearchPage.tsx's attribute filter
    // <select>s offer, scoped the same way search itself is.
    Route::get('/search/filter-options', [SearchController::class, 'filterOptions']);
    // GitHub issue #121 — the current result set as a PDF, same filter
    // params search() above accepts (see SearchController::filtersFromRequest()).
    Route::get('/search/export/pdf', [SearchController::class, 'exportPdf']);
    // GitHub issue #73's "nice to have": named, reusable filter
    // combinations, personal to the requesting user (not shared/library-
    // scoped, so no LibraryAccessService check beyond the ordinary
    // auth:sanctum/active gate every route in this group already has).
    Route::get('/saved-searches', [SavedSearchController::class, 'index']);
    Route::post('/saved-searches', [SavedSearchController::class, 'store']);
    Route::delete('/saved-searches/{savedSearch}', [SavedSearchController::class, 'destroy']);
    Route::get('/statistics', StatisticsController::class);
    // Value-over-time (briefing 14. "Zeitlicher Zuwachs des Bestands", GitHub
    // issue #30) — separate endpoint rather than folded into /statistics
    // above, keeping that response shape unchanged for existing consumers.
    Route::get('/statistics/value-history', [StatisticsController::class, 'valueHistory']);

    // "Auswertungen" (GitHub issue #74) — tables a user browses row by row,
    // deliberately a separate module from /statistics above (see
    // ReportsService's docblock), scoped through LibraryAccessService the
    // same way.
    Route::get('/reports/duplicates', [ReportsController::class, 'duplicates']);
    Route::get('/reports/data-quality', [ReportsController::class, 'dataQuality']);
    Route::get('/reports/top-lists', [ReportsController::class, 'topLists']);
    Route::get('/reports/recent-additions', [ReportsController::class, 'recentAdditions']);
    Route::get('/reports/capture-source', [ReportsController::class, 'captureSource']);
    // Per-library sharing overview and per-user capture activity (GitHub
    // issue #74) — moved here from /statistics/* by GitHub issue #103, see
    // ReportsService's docblock for why.
    Route::get('/reports/sharing', [ReportsController::class, 'sharing']);
    Route::get('/reports/user-activity', [ReportsController::class, 'userActivity']);
    // PDF export (GitHub issue #87) of any report above — declared after
    // every static /reports/<key> route, same reasoning GitHub issue #54's
    // bulk-delete route ordering comment already documents: a route with a
    // wildcard segment ({key}) needs to come after the more specific static
    // ones it could otherwise shadow. Not applicable here in practice
    // (every route above uses GET, this one has an extra /export/pdf
    // suffix no other route shares), but kept in the same relative order
    // for readability regardless.
    Route::get('/reports/{key}/export/pdf', [ReportsController::class, 'exportPdf']);

    // Libraries — readable per LibraryAccessService; write endpoints re-check
    // ownership/admin inside the controller (5., 4.3). Guests never reach
    // store/update/destroy because they only get level 'guest'; the model
    // binding + in-controller check is what actually enforces it.
    Route::get('/libraries', [LibraryController::class, 'index']);
    Route::get('/libraries/{library}', [LibraryController::class, 'show']);

    // GitHub issue #179: per-user exclude_from_statistics/exclude_from_reports/
    // exclude_from_dashboard preferences (LibraryUserPreference) — a personal
    // setting anyone who can *read* a library may set for themselves, so this
    // deliberately sits outside the level:user,admin group below just like the
    // library-read routes just above (a guest with a shared library included).
    Route::get('/library-preferences', [LibraryPreferenceController::class, 'index']);
    Route::put('/libraries/{library}/preference', [LibraryPreferenceController::class, 'update']);

    // Pure reads of a library's contents — like the library routes just
    // above, gated only by MediaItemController's own canRead() check, not
    // by level middleware (GitHub issue #38). A guest's whole usable
    // scenario is reading a library explicitly shared with them
    // (briefing 4.2: "Kann ausschließlich Bibliotheken lesen, die explizit
    // für Gäste freigegeben wurden."); putting these behind
    // level:user,admin blocked every guest here with a blanket 403 before
    // the request ever reached that check, making a guest-shared library
    // visible in the list but never actually readable.
    Route::get('/libraries/{library}/items', [MediaItemController::class, 'index']);
    Route::get('/libraries/{library}/items/{item}', [MediaItemController::class, 'show']);
    Route::get('/libraries/{library}/items/{item}/cover', [MediaItemController::class, 'cover']);
    Route::get('/libraries/{library}/items/{item}/cover/thumbnail', [MediaItemController::class, 'coverThumbnail']);
    // Printable/archivable PDF inventory list (GitHub issue #87) — a read
    // action like the routes just above, not a management one.
    Route::get('/libraries/{library}/export/pdf', [LibraryController::class, 'exportPdf']);

    Route::middleware('level:user,admin')->group(function () {
        Route::post('/libraries', [LibraryController::class, 'store']);
        Route::put('/libraries/{library}', [LibraryController::class, 'update']);
        Route::delete('/libraries/{library}', [LibraryController::class, 'destroy']);
        Route::put('/libraries/{library}/shares', [LibraryController::class, 'updateShares']);
        Route::put('/libraries/{library}/owner', [LibraryController::class, 'transferOwnership']);
        // Share-target picker for updateShares() above — see UserController::shareable()'s docblock.
        Route::get('/users', [UserController::class, 'shareable']);

        // Capture / media items — write access re-checked per-library inside
        // the controllers (owner or admin), see 7. and 6.
        Route::post('/libraries/{library}/items/{item}/cover', [MediaItemController::class, 'uploadCover']);
        Route::delete('/libraries/{library}/items/{item}/cover', [MediaItemController::class, 'deleteCover']);
        Route::post('/libraries/{library}/items', [MediaItemController::class, 'store']);
        // GitHub issue #54 — declared before the {item}-parameterized routes
        // below (a static "bulk-delete" segment could otherwise be matched
        // as {item} by a less specific route ordering).
        Route::post('/libraries/{library}/items/bulk-delete', [MediaItemController::class, 'bulkDestroy']);
        // GitHub issue #63 — same reasoning as bulk-delete above about declaring this before the {item}-parameterized routes.
        Route::post('/libraries/{library}/items/bulk-update', [MediaItemController::class, 'bulkUpdate']);
        Route::put('/libraries/{library}/items/{item}', [MediaItemController::class, 'update']);
        Route::delete('/libraries/{library}/items/{item}', [MediaItemController::class, 'destroy']);
        Route::post('/libraries/{library}/items/{item}/move', [MediaItemController::class, 'move']);

        Route::post('/libraries/{library}/capture/scan', [CaptureController::class, 'scan']);
        Route::post('/libraries/{library}/capture/textfile', [CaptureController::class, 'textFile']);

        Route::get('/libraries/{library}/metadata/search', [MetadataController::class, 'search']);
        Route::post('/libraries/{library}/metadata/import', [MetadataController::class, 'import']);
        // GitHub issue #56: re-query metadata for an *existing* item (GET returns
        // the same {candidates, merged} shape resolveOne()/lookupMerged() already
        // produce, for MetadataMergeReview.tsx reuse; POST applies the user's
        // per-field picks to that item instead of creating a new one).
        Route::get('/libraries/{library}/items/{item}/metadata/refresh', [MetadataController::class, 'refresh']);
        Route::post('/libraries/{library}/items/{item}/metadata/refresh', [MetadataController::class, 'reimport']);
    });

    // Administration (briefing 15.) — admin only.
    Route::middleware('level:admin')->prefix('admin')->group(function () {
        Route::apiResource('users', UserController::class)->except(['show']);
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);
        Route::post('/users/{user}/reactivate', [UserController::class, 'reactivate']);

        // GitHub issue #37: plugins() serializes MetadataPlugin.config as-is,
        // which is where a provider's admin-configured API key lives
        // (e.g. UpcMdbProvider) — "exactly as sensitive as a password hash"
        // per this file's own docs on metadata_plugins. This used to sit
        // outside the admin group (auth:sanctum/active only), so any
        // logged-in account, guest included, could read every stored key.
        // Only PluginsPage.tsx (admin-only) ever calls this.
        Route::get('/metadata/plugins', [MetadataController::class, 'plugins']);
        Route::put('/metadata/plugins/{plugin}', [MetadataController::class, 'updatePlugin']);
        // GitHub issue #160 — tests a candidate config (not necessarily saved yet) against the real provider API.
        Route::post('/metadata/plugins/{plugin}/test', [MetadataController::class, 'testPluginConfig']);

        Route::post('/export', [ExportImportController::class, 'export']);
        Route::post('/import', [ExportImportController::class, 'import']);

        Route::get('/backups', [BackupController::class, 'index']);
        Route::post('/backups', [BackupController::class, 'store']);
        // GitHub issue #167 — the upload counterpart to store() above.
        Route::post('/backups/upload', [BackupController::class, 'upload']);
        Route::get('/backups/{backup}/download', [BackupController::class, 'download']);
        Route::delete('/backups/{backup}', [BackupController::class, 'destroy']);
        Route::post('/backups/{backup}/restore', [BackupController::class, 'restore']);

        Route::get('/settings', [AdminSettingsController::class, 'index']);
        Route::put('/settings/mail', [AdminSettingsController::class, 'updateMail']);
        Route::post('/settings/mail/test', [AdminSettingsController::class, 'testMail']);
        Route::put('/settings/backup', [AdminSettingsController::class, 'updateBackup']);
        Route::put('/settings/security', [AdminSettingsController::class, 'updateSecurity']);
        Route::put('/settings/covers', [AdminSettingsController::class, 'updateCoverCleanup']);
        // GitHub issue #202 — toggles GitHub issue #201's admin-only EAN editor.
        Route::put('/settings/ean-editing', [AdminSettingsController::class, 'updateEanEditing']);
        Route::put('/settings/loglevel', [AdminSettingsController::class, 'updateLoglevel']);
        Route::put('/settings/locale', [AdminSettingsController::class, 'updateLocale']);
        Route::put('/settings/timezone', [AdminSettingsController::class, 'updateTimezone']);
        Route::put('/settings/statistics', [AdminSettingsController::class, 'updateStatistics']);
        Route::put('/settings/oidc', [AdminSettingsController::class, 'updateOidc']);

        // Actual "only admins may add a language pack" enforcement (briefing
        // 11.4/17., GitHub issue #12) — reading a pack is public, see
        // GET /languages(/{languagePack}) near the top of this file.
        Route::post('/languages', [LanguagePackController::class, 'store']);
        Route::put('/languages/{languagePack}', [LanguagePackController::class, 'update']);
        Route::delete('/languages/{languagePack}', [LanguagePackController::class, 'destroy']);

        // Repo-shipped languagepacks/*.json packs (pre-installed on fresh
        // boot, DatabaseSeeder) — lets an admin (re)install one on demand,
        // e.g. after deleting it, or once a later image update ships a new
        // one, without a restart. /bundled doesn't collide with the
        // {languagePack} route-model-bound routes above (different HTTP
        // methods, and no GET/DELETE on /languages/bundled exists here).
        Route::get('/languages/bundled', [LanguagePackController::class, 'bundled']);
        Route::post('/languages/bundled/{code}', [LanguagePackController::class, 'installBundled']);

        // Actual "only admins may add a template" enforcement (briefing
        // 10./11.4, GitHub issue #11) — reading a template is public, see
        // GET /templates(/{template}) near the top of this file.
        Route::post('/templates', [TemplateController::class, 'store']);
        Route::put('/templates/{template}', [TemplateController::class, 'update']);
        Route::delete('/templates/{template}', [TemplateController::class, 'destroy']);

        // Repo-shipped templates/*.json files (pre-installed on fresh boot,
        // DatabaseSeeder) — lets an admin (re)install one on demand, same
        // reasoning as /languages/bundled above.
        Route::get('/templates/bundled', [TemplateController::class, 'bundled']);
        Route::post('/templates/bundled/{code}', [TemplateController::class, 'installBundled']);
    });
});
