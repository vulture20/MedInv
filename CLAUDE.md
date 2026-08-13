# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

MedInv is a self-hosted web app for managing physical media collections (books, CDs, DVDs/Blu-ray) across multiple independent, permission-scoped "libraries". The full requirements/behavior spec is **[`docs/medinv-briefing.md`](docs/medinv-briefing.md)** (German) — it is the source of truth for *what* the app should do; treat it as authoritative over inferring behavior from code alone, and cite its chapter numbers (e.g. "briefing 5.1") in comments/commits when implementing something it specifies. The codebase is an early scaffold built from that spec — it was itself generated with Claude Code against that spec (see README.md's "About this project" / README.de.md's "Über dieses Projekt"), not hand-written; expect it to still read that way. Core CRUD, auth, and permission plumbing are implemented and tested end-to-end; several integration points are intentionally stubbed with `TODO` comments pointing back at the relevant chapter (see "Known-incomplete areas" below) — don't assume a feature is finished just because a controller/service file for it exists.

Stack: Laravel 13 API (`backend/`) + React/TypeScript SPA (`frontend/`), deployed as one Docker image with nginx + php-fpm (`docker/`). Current version: see `backend/config/medinv.php` (single source of truth, exposed at `GET /api/version`).

## Commands

### Backend (`backend/`)
```bash
composer install
touch database/database.sqlite && php artisan migrate   # sqlite is the default MEDINV_DB_CONNECTION
php artisan db:seed              # creates the MEDINV_ADMINUSER/MEDINV_ADMINPASS account (see .env)
php artisan serve                # http://localhost:8000

php artisan test                                   # full suite
php artisan test --filter=TestClassName::test_name # single test
./vendor/bin/pint                                   # fix code style (Laravel/PSR-12); add --test to check without fixing
```

### Frontend (`frontend/`)
```bash
npm install
npm run dev      # http://localhost:5173, talks to backend via VITE_API_BASE_URL (.env)
npx tsc -b       # type-check
npx oxlint       # lint
npm run build    # production build -> dist/
```

### Docker (single image, both apps)
```bash
cd docker
MEDINV_ADMINUSER=admin@example.com MEDINV_ADMINPASS='ChangeMe123!' docker compose up --build
# -> http://localhost:8080 (SPA + same-origin API under /api + /sanctum — no separate API port).
# Override with MEDINV_PortWeb. Add MEDINV_DB_CONNECTION=mariadb|postgres + --profile mariadb|postgres to switch DB backend.
```

## Architecture

### Two apps, one deployable image, one configurable port
`backend/` and `frontend/` are independently run and built (separate `.env`s, separate dev servers) but ship as **one** Docker image (`docker/Dockerfile`): nginx listens on a single port, rendered into `nginx.conf` from `docker/nginx.conf.template` at container start via `envsubst` (`docker/entrypoint.sh`) since nginx can't read env vars directly. `MEDINV_PortWeb` (default 8080) serves the built SPA as static files *and* reverse-proxies `/api/*` + `/sanctum/*` straight to php-fpm, same-origin. This is what every consumer talks to, browser or not — the frontend is built with `VITE_API_BASE_URL=""` for that image so `frontend/src/api/client.ts` calls relative URLs, and same-origin means the deployed app needs no CORS config. There is deliberately no second, dedicated API-only port (an earlier iteration of this project had one, `MEDINV_PortAPI`, and removed it) — keeping a single port with the API strictly confined to the `/api`/`/sanctum` prefixes (`docker/nginx.conf.template`'s `location ^~` blocks) is simpler to reason about and to firewall than two parallel entrypoints into the same backend.

In local dev, backend and frontend run on two ordinary ports (5173 / 8000 by default, `php artisan serve --port=`), which *does* need CORS + Sanctum's stateful-domain config (see below).

### Auth: Sanctum SPA cookies, not tokens
Login is cookie/session-based (`AuthController`), not bearer tokens. This has a specific, easy-to-get-wrong requirement: a request is only treated as "stateful" (session + CSRF middleware applied) if its `Origin`/`Referer` header matches an entry in `config('sanctum.stateful')` (`backend/config/sanctum.php`, driven by `SANCTUM_STATEFUL_DOMAINS`) — a mismatch (e.g. testing against `127.0.0.1:X` while the domain list says `localhost:X`) fails *silently* as `419`/`500`/session errors, not as a clear auth error. When reproducing auth issues with curl, always send a matching `Origin` header and hit `/sanctum/csrf-cookie` first. `backend/config/cors.php` is a separate, additional requirement for genuinely cross-origin dev (5173 -> 8000) — same-origin production requests don't need it.

**This has bitten real users, not just tests**: a fresh `docker compose up` deployment failed to log in with correct credentials and showed a generic "invalid credentials" message, root-caused to two compounding issues (both now fixed, but the failure mode is worth knowing): (1) `APP_KEY` wasn't set, which throws `MissingAppKeyException` on every request touching cookies — `docker/entrypoint.sh` now auto-generates and persists one in the `storage` volume on first boot, so this shouldn't recur; (2) the accepted login origins didn't cover both hostnames a browser might actually use. `docker/entrypoint.sh` now computes `SANCTUM_STATEFUL_DOMAINS` itself (unless set explicitly) as `localhost:$MEDINV_PortWeb`, `127.0.0.1:$MEDINV_PortWeb`, plus the host parsed out of `MEDINV_URL` if that's set (the public URL a real deployment behind a reverse proxy/domain is reachable at — also used as `APP_URL`). If login ever silently fails again in Docker, check `storage/logs/laravel.log` inside the container before assuming it's a credentials problem — `AuthController`'s `invalid_credentials` error_code is only returned for an actual wrong password/locked/deactivated account; anything else (a 500, a network error) surfaces client-side as the generic `errors.generic` translation instead (see `LoginPage.tsx`) specifically so it's *not* confused with a credentials error again.

### Domain-organized backend, not the Laravel default
Business logic lives in `backend/app/Domain/<Area>/` (Libraries, Metadata, Capture, Backup, ExportImport, Search, Statistics, Security, Mail), one namespace per functional module from briefing chapter 10 ("maximale Modularität"), each injected into thin controllers under `app/Http/Controllers/Api/`. When adding a feature, put the logic in a `Domain` service, not the controller.

Two rules are centralized and must stay that way — every write path routes through them rather than re-implementing the check:
- **Library visibility/write access** (briefing 4.2–4.3: guest/user/admin levels × per-library shares) — `App\Domain\Libraries\LibraryAccessService`. `SearchService` and `StatisticsService` both scope their queries through its `visibleLibrariesQuery()` so an unshared library is invisible there too, per the spec.
- **Per-library duplicate-EAN rejection** (briefing 5.1: same EAN allowed across libraries, forbidden twice in one) — `App\Domain\Libraries\MediaItemService::create()`, used by manual entry, bulk capture, metadata import, and backup/export restore alike.

### Three media types, one pattern each
Book/CD/DVD-Blu-ray are three separate tables (`MediaBook`/`MediaCd`/`MediaDvdBluray`) with a fixed, non-overlapping attribute set per briefing chapter 6 — there's no generic "extra field" mechanism by design. A `Library.media_type` is immutable after creation (no update path exists for it anywhere in `LibraryController`). Code that needs to be type-generic (capture, export/import, stats) resolves the right model via `MediaItemService::modelClassFor()` rather than branching on `media_type` ad hoc.

### Metadata plugins are a real interface, not a config flag
`App\Domain\Metadata\Contracts\MetadataProviderInterface` + `MetadataProviderRegistry` (briefing 8.1) is the extension point for new metadata sources — implement the interface under `app/Domain/Metadata/Providers/<MediaType>/`, list the class in `MetadataProviderRegistry::defaultProviders()`, and it becomes toggleable via `metadata_plugins` (admin API) automatically. Only one example provider per media type is implemented (OpenLibrary, MusicBrainz, UPCMDB); the rest of briefing 8.2's list are `TODO`s in the registry, not missing files to hunt for. `MetadataImportService` only ever calls a provider that has a corresponding, *enabled* `metadata_plugins` row (`MetadataProviderRegistry::enabledProvidersFor()`) — listing a class in `defaultProviders()` alone doesn't activate it. `DatabaseSeeder` calls `syncToDatabase()` (`firstOrCreate`-based, so it's safe to run on every boot) specifically so a fresh install has all default providers enabled from the start; this was in fact missing until GitHub issue #17's investigation surfaced it — before that fix, a truly fresh install had zero enabled providers and every capture/search silently returned "no_match" no matter what was implemented.

### Every MedInv-created table is prefixed `MEDINV_`
Set once via `MEDINV_DB_PREFIX` in `config/database.php` (applied to all four connections: sqlite/mysql/mariadb/pgsql) so the app can share a DB server without collisions (briefing 5.). This is why raw SQL/`DB::table()` calls must not hardcode table names — use Eloquent models or `$this->getTable()`, which already account for the prefix.

### All environment variables are `MEDINV_`-prefixed, including the database ones
Laravel's stock `DB_*` config keys (`DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_PREFIX`, and the rest) are renamed to `MEDINV_DB_*` throughout `backend/config/database.php` (and the `database`-driver options in `config/cache.php`/`config/queue.php`, which fall back to the same connection) — a deliberate deviation from Laravel's defaults so that *every* variable this project reads from the environment consistently starts with `MEDINV_`, matching the vars from briefing chapter 16. When adding a new Laravel config option that reads `env('DB_...')` by default (e.g. from a fresh `composer require`), rename it the same way rather than leaving a stray unprefixed `DB_*` var.

### Environment variables vs. runtime settings — don't confuse the two
Only the handful in briefing chapter 16 (`MEDINV_ADMINUSER`, `MEDINV_ADMINPASS`, `MEDINV_LOGLEVEL`, `MEDINV_TRUSTEDIP`, `MEDINV_RESTOREBACKUP`) plus the database/deployment ones above (`MEDINV_DB_*`, `MEDINV_URL`, `MEDINV_PortWeb`) are env vars, read directly via `env()` (seeder, `BruteForceProtection`, `docker/entrypoint.sh`). Everything else admin-configurable — mail/SMTP, backup interval & retention, brute-force thresholds — lives in the `system_settings` key-value table (`App\Models\SystemSetting::get()/set()`, `AdminSettingsController`) and is meant to be changed at runtime without a restart. `App\Providers\AppServiceProvider::boot()` copies the `mail.*` settings into Laravel's live mail config on every request specifically so `Mail`/`Password::sendResetLink()` pick up the admin-configured SMTP server instead of `.env`'s.

### API errors carry a machine-readable `error_code`, not just prose
`AuthController::loginError()` returns `{error_code, message}` instead of throwing `ValidationException` — the frontend maps `error_code` to a translated string via `errors.*` i18n keys (`LoginPage.tsx`), rather than pattern-matching on the English `message` text, which would break under a locale change or if the message wording changes. Follow this pattern for any other user-facing API error: add an `error_code`, not just a message, and a matching `errors.<code>` key in both `frontend/src/i18n/locales/{de,en}.json`. Also route it through `Controller::logApiError($request, $errorCode, $message)` (base `App\Http\Controllers\Controller`) so it lands in `storage/logs/laravel.log` with the requesting client's IP alongside the code and message — used today by `AuthController::loginError()`, `UserController::protectedAccountResponse()` and `AdminSettingsController::testMail()`'s failure path.

### Backups live under `storage/app/private/backups`, not `storage/app/backups`
Laravel 11+'s default `local` filesystem disk root is `storage/app/private` (`backend/config/filesystems.php`), not `storage/app` — `BackupService` uses `Storage::disk('local')`, so that's where `.zip` files actually land. Easy to get wrong when adding new storage paths; check `config/filesystems.php` rather than assuming the pre-11 default.

### The DB is update-ready: migrations run automatically, with a safety net
`docker/entrypoint.sh` runs `php artisan migrate --force` on every container start, so a new image with new migration files applies its schema changes with no manual step. Immediately before that, it runs `php artisan medinv:pre-update-backup` (`app/Console/Commands/PreUpdateBackupCommand.php`), which creates a backup via `BackupService` — but only when there are pending migrations *and* the database was already initialized (i.e. this is a real update, not first install or an unchanged restart) — so an update has a restore point without backing up on every ordinary restart.

### Backup restore has two trigger paths, one shared implementation
`BackupService::restore()` (briefing 9.3) reads a backup zip's `manifest.json` back out and hands it to `ExportImportService::importLibraries()` — the same conflict-resolution logic (rename/merge/overwrite/skip/cancel) instance-to-instance import already uses (briefing 9.1), so restore isn't a separate code path. The two triggers differ only in who picks the per-library resolution: `BackupController::restore()` takes `conflict_resolutions`/`restore_settings` from the admin UI request interactively, while `Console\Commands\RestoreBackupOnBoot` (`php artisan medinv:restore-backup <filename>`, run from `docker/entrypoint.sh` whenever `MEDINV_RESTOREBACKUP` is set) has no admin to ask and instead overwrites every conflicting library unconditionally via `importLibraries()`'s `__default__` conflict-resolution sentinel, plus always restores settings and user accounts — `MEDINV_RESTOREBACKUP`'s whole purpose is bringing the instance to exactly the backed-up state, e.g. resetting a demo/staging deployment on every restart.

### Automatic backups need both a Laravel schedule entry and something to actually invoke it
`backup.interval_mode`/`backup.cron_expression` (briefing 9.2, admin-configurable via `AdminSettingsController::updateBackup()`) are resolved into an actual cron expression by `BackupService::scheduledBackupCronExpression()`, registered in `routes/console.php` — but that alone isn't enough, since this image has no system cron daemon. `docker/supervisord.conf` runs a third process (`[program:scheduler]`) alongside nginx/php-fpm, a plain shell loop calling `php artisan schedule:run` every 60 seconds — Laravel's own scheduler resolution. The task itself is registered as `->everyMinute()`, not a dynamic `->cron($expression)`: `routes/console.php` loads on *every* console bootstrap (any artisan command, not just `schedule:run`), so evaluating `SystemSetting::get()` at registration time broke `php artisan migrate --force` itself on a brand-new database (no `system_settings` table yet) — the actual due-check happens inside the scheduled closure instead, via `Cron\CronExpression::isDue(Carbon::now())` (the explicit `Carbon::now()` matters too — `isDue()`'s own `'now'` default builds a plain `DateTime`, which ignores `Carbon::setTestNow()` and made this untestable otherwise).

### Fuzzy search is backend-specific, with a portable fallback everywhere else
`SearchService`'s `fuzzy` flag (briefing 13.) resolves differently depending on the active `MEDINV_DB_CONNECTION`, since there's no single privilege-free, index-usable typo-tolerant primitive across sqlite/mariadb/pgsql. On Postgres with the `pg_trgm` extension actually installed, it uses `word_similarity`'s indexable `%>` operator against a GIN trigram index built on `LOWER(column)` (a migration, `database/migrations/*_add_pg_trgm_indexes_for_media_search.php`, iterates `SearchService::SEARCHABLE_COLUMNS` — the single source of truth for searchable columns — to create these; deliberately `word_similarity`/`%>`, not plain `similarity()`/`%`, since the latter scores against a compared string's *entire* length and badly under-scores a short query cleanly matching inside a long field like `description`). Everywhere else (sqlite, mariadb/mysql, or a pgsql connection where `CREATE EXTENSION pg_trgm` failed — self-hosted Postgres instances don't always grant that privilege, and the migration is deliberately non-fatal if it does) falls back to `FuzzyTextMatcher`, a pure PHP word-level Levenshtein matcher: since a typo'd query word has by definition no substring overlap with the correctly-spelled field value, there's no SQL-level `LIKE` pre-filter that could narrow the candidate set without risking excluding the very row that should match, so this path loads every row in the visible libraries (still scoped through `LibraryAccessService`) and matches in PHP — an accepted trade-off given this app's realistic data volumes. `SearchService::pgTrgmAvailable()` checks (cached via the `database` cache store, not an in-process property — `SearchService` isn't a singleton, so per-request state buys nothing) whether the extension actually ended up installed, so a privilege-restricted Postgres deployment gets the same behavior as sqlite/mariadb rather than an error.

## Known-incomplete areas (check before assuming something works)

- `StatisticsService`'s distributions cover genre/language/year/publisher-artist-director (briefing 14., GitHub issue #7) but not "Zeitlicher Zuwachs des Bestands" (growth over time) — that needs historical snapshots, not just aggregating current state, and is a separate feature.
- Most metadata provider plugins from briefing 8.2 (Hardcover, Amazon, Google Books, Discogs, Emunation.ch) don't exist yet — see `MetadataProviderRegistry::defaultProviders()`.
- Additional UI templates/language packs beyond the shipped light/dark + de/en are a stated extension point (briefing 10./11.4) but there's no installable-plugin mechanism for them yet — templates are a hardcoded `'light'|'dark'` union.
