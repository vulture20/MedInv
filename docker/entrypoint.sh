#!/bin/sh
set -e

# Central place where every MEDINV_* environment variable (briefing chapter
# 16.) is evaluated at container start, per the "Leitgedanken" in chapter 19.
cd /var/www/backend

# Self-healing fix for GitHub issue #91: this whole script runs as root (no
# USER directive anywhere in the Dockerfile), and so does every `php artisan`
# call below (migrate/seed/pre-update-backup) — if any of them writes to
# Laravel's log (config/logging.php's `daily` channel, GitHub issue #85), the
# file it creates is owned by root:root, not www-data:www-data. The php-fpm
# workers that handle every actual HTTP request run as www-data and can't
# write to a root-owned log file, which surfaced as the whole app silently
# hanging on the login screen — a failed write inside Laravel's own logging
# pipeline, mid-request, rather than a clean error page. supervisord.conf's
# scheduler (`schedule:run`, running once a minute around the clock) is the
# single likeliest process to hit this the moment a new calendar day's log
# file is first needed, and now runs as www-data itself for the same reason
# — but this also repairs a file already left root-owned by a previous boot
# (before that fix existed, or from one of entrypoint.sh's own artisan calls
# below), so a broken deployment self-heals on the next restart instead of
# needing a manual `docker compose exec app chown` from the operator. `-R`
# despite this normally only ever needing to fix files, not the directory
# itself, is deliberate: recovering from a wholly-missing directory (an old
# volume predating a chown ever happening at all) at no extra cost.
mkdir -p storage/logs
chown -R www-data:www-data storage/logs

# APP_KEY encrypts sessions/cookies — without it, Sanctum's stateful
# middleware throws MissingAppKeyException on every request (including
# login), which previously surfaced as a misleading generic error instead
# of a clear setup problem. Rather than requiring it as a mandatory env var
# like MEDINV_ADMINUSER/PASS, generate one on first boot and persist it in
# the `storage` volume (docker-compose.yml) so it's stable across container
# recreation — an APP_KEY that changes invalidates all existing sessions.
# No .env file ships in the image (see .dockerignore), so this is exported
# directly rather than written via `artisan key:generate`, which expects one.
APP_KEY_FILE="/var/www/backend/storage/app_key"
if [ -z "$APP_KEY" ]; then
    if [ -f "$APP_KEY_FILE" ]; then
        APP_KEY=$(cat "$APP_KEY_FILE")
    else
        echo "No APP_KEY set — generating one and persisting it to $APP_KEY_FILE"
        mkdir -p "$(dirname "$APP_KEY_FILE")"
        APP_KEY=$(php artisan key:generate --show)
        echo "$APP_KEY" > "$APP_KEY_FILE"
        chown www-data:www-data "$APP_KEY_FILE"
        chmod 600 "$APP_KEY_FILE"
    fi
fi
export APP_KEY

# MEDINV_LOGLEVEL (DEBUG/INFO/WARNING/ERROR) -> Laravel's lowercase LOG_LEVEL.
# Runtime overrides via the admin UI are stored in system_settings instead
# (see AdminSettingsController) and take precedence there, not here.
if [ -n "$MEDINV_LOGLEVEL" ]; then
    export LOG_LEVEL=$(echo "$MEDINV_LOGLEVEL" | tr '[:upper:]' '[:lower:]')
fi

# Laravel's SQLite driver requires the file to pre-exist (it won't
# auto-create it) — relevant when MEDINV_DB_CONNECTION=sqlite (the default,
# briefing 10.: DB backend chosen freely at setup). All DB_* Laravel
# defaults are renamed to MEDINV_DB_* for this project (config/database.php)
# so every MedInv-specific setting consistently starts with MEDINV_.
#
# The path is fixed and authoritative here, deliberately NOT reading an
# inherited MEDINV_DB_DATABASE (unlike every other MEDINV_DB_* var) — see
# GitHub issue #25. Two compounding bugs used to live here: (1)
# docker-compose.yml sets MEDINV_DB_DATABASE=medinv by default, a value
# that's only meaningful for mysql/mariadb/postgres (a database *name*, not
# a file path) — inherited here as a bogus relative path, it silently
# created the database at ./medinv (relative to `cd /var/www/backend`
# above), entirely outside any mounted volume, so it never survived a
# container recreation at all; (2) even fixing #1, the path this used to
# fall back to (database/database.sqlite) lives inside the `database/`
# application-code directory, which docker-compose.yml also mounted a
# volume onto — meaning every future image update's changes to
# database/migrations|seeders|factories got silently shadowed by that
# volume's now-frozen copy from whenever it was first created, forever.
# The database now lives under storage/database instead — nested inside
# storage/, which was already a volume reserved for exactly this kind of
# persisted runtime state (backups, logs, this file). For docker-compose
# deployments this is handled transparently: docker-compose.yml still
# declares the same *named* volume (`sqlite-data`) an existing deployment's
# real data already lives in, just remounted at the new nested path
# instead of the old, code-shadowing one — Compose matches volumes by
# name, so the data carries over with no action needed here at all.
if [ "${MEDINV_DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    SQLITE_PATH="/var/www/backend/storage/database/database.sqlite"

    # Defensive fallback for anyone running the image directly (not via
    # docker-compose.yml) who had mounted their own volume at the old
    # path: a database.sqlite file found there gets moved into the new
    # location on next boot, before migrate runs, instead of silently
    # starting over on an empty database. Safe on every boot: a no-op
    # once moved (or if nothing was ever there — the compose path above
    # never populates this old location at all).
    OLD_SQLITE_PATH="/var/www/backend/database/database.sqlite"
    if [ -f "$OLD_SQLITE_PATH" ] && [ ! -f "$SQLITE_PATH" ]; then
        echo "Found a database at the old location ($OLD_SQLITE_PATH) — moving it to $SQLITE_PATH (see GitHub issue #25)."
        mkdir -p "$(dirname "$SQLITE_PATH")"
        mv "$OLD_SQLITE_PATH" "$SQLITE_PATH"
    fi

    mkdir -p "$(dirname "$SQLITE_PATH")"
    touch "$SQLITE_PATH"
    # php-fpm workers run as www-data (see php-fpm's default pool config) —
    # the file/dir must be writable by that user, not just root (this
    # entrypoint's own user).
    chown www-data:www-data "$(dirname "$SQLITE_PATH")" "$SQLITE_PATH"
    export MEDINV_DB_DATABASE="$SQLITE_PATH"
fi

echo "Waiting for database connection..."
until php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); Illuminate\Support\Facades\DB::connection()->getPdo();" > /dev/null 2>&1; do
    sleep 1
done

# Safety-net backup before applying any new migrations from this update
# (no-op on a fresh install or when nothing changed — see the command's
# docblock). This is what makes the DB ready for program updates that bring
# schema changes: migrations themselves are already additive, and this adds
# an automatic rollback point on top.
php artisan medinv:pre-update-backup
php artisan migrate --force

# MEDINV_ADMINUSER / MEDINV_ADMINPASS: initial admin account (briefing
# 4.1), read directly from the environment by DatabaseSeeder. Idempotent —
# firstOrCreate() means re-running on every boot is safe.
php artisan db:seed --force

# MEDINV_RESTOREBACKUP: restore a named backup on boot (briefing 9.3), for
# automated deployments — the unattended counterpart to the interactive
# admin-UI restore. Runs on every boot the variable is set, same as
# db:seed above; see RestoreBackupOnBoot's docblock for why it overwrites
# conflicting libraries and restores settings/users unconditionally rather
# than asking (there's no admin present to ask). A failure here (unknown
# filename, no admin account yet) is logged by the command itself and does
# not stop the container from starting — a bad MEDINV_RESTOREBACKUP value
# shouldn't brick an otherwise-working instance.
if [ -n "$MEDINV_RESTOREBACKUP" ]; then
    php artisan medinv:restore-backup "$MEDINV_RESTOREBACKUP" || true
fi

# MEDINV_TRUSTEDIP is read directly via env() at request time
# (App\Domain\Security\BruteForceProtection) — nothing to do here.

# MEDINV_PortWeb: the single port nginx listens on (serves the SPA and
# proxies /api + /sanctum to php-fpm — see docker/nginx.conf.template) —
# rendered into nginx.conf from the template here since nginx itself can't
# read environment variables. Default keeps `docker run` usable without
# setting it explicitly.
: "${MEDINV_PortWeb:=8080}"
export MEDINV_PortWeb
envsubst '${MEDINV_PortWeb}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# SANCTUM_STATEFUL_DOMAINS: which request Origins Sanctum treats as a
# same-site browser session (see CLAUDE.md's "Auth: Sanctum SPA cookies"
# section) — a mismatch here is the single most common cause of "correct
# credentials, login still fails" reports. localhost/127.0.0.1 on
# MEDINV_PortWeb are always included since those are the two hostnames a
# browser hitting this container directly will use; if MEDINV_URL is set
# (the public URL this instance is actually reachable at, e.g. behind a
# reverse proxy on a real domain), its host[:port] is added too. Explicitly
# setting SANCTUM_STATEFUL_DOMAINS yourself skips all of this and is used
# as-is, for cases this heuristic doesn't cover.
if [ -z "$SANCTUM_STATEFUL_DOMAINS" ]; then
    SANCTUM_STATEFUL_DOMAINS="localhost:${MEDINV_PortWeb},127.0.0.1:${MEDINV_PortWeb}"
    if [ -n "$MEDINV_URL" ]; then
        # Strip a leading scheme and any trailing path/slash, leaving just
        # host[:port] — the shape Sanctum's stateful-domain list expects.
        MEDINV_URL_HOST=$(echo "$MEDINV_URL" | sed -E 's#^[A-Za-z][A-Za-z0-9+.-]*://##; s#/.*$##')
        # Skip if it's already covered by localhost/127.0.0.1 above (e.g.
        # MEDINV_URL=http://127.0.0.1:$MEDINV_PortWeb during local testing)
        # so the list doesn't carry a pointless duplicate entry.
        case ",$SANCTUM_STATEFUL_DOMAINS," in
            *",$MEDINV_URL_HOST,"*) ;;
            *) [ -n "$MEDINV_URL_HOST" ] && SANCTUM_STATEFUL_DOMAINS="$SANCTUM_STATEFUL_DOMAINS,$MEDINV_URL_HOST" ;;
        esac
    fi
fi
export SANCTUM_STATEFUL_DOMAINS

# APP_URL similarly defaults to MEDINV_URL when set, so links generated by
# the backend (e.g. in password-reset mail) point at the real public URL
# instead of localhost.
: "${APP_URL:=${MEDINV_URL:-http://localhost:${MEDINV_PortWeb}}}"
export APP_URL

exec "$@"
