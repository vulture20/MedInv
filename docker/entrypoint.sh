#!/bin/sh
set -e

# Central place where every MEDINV_* environment variable (briefing chapter
# 16.) is evaluated at container start, per the "Leitgedanken" in chapter 19.
cd /var/www/backend

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
if [ "${MEDINV_DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    SQLITE_PATH="${MEDINV_DB_DATABASE:-/var/www/backend/database/database.sqlite}"
    mkdir -p "$(dirname "$SQLITE_PATH")"
    touch "$SQLITE_PATH"
    # php-fpm workers run as www-data (see php-fpm's default pool config) —
    # the file/dir must be writable by that user, not just root (this
    # entrypoint's own user).
    chown www-data:www-data "$(dirname "$SQLITE_PATH")" "$SQLITE_PATH"
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

# MEDINV_RESTOREBACKUP: restore a named backup on boot (briefing 9.3).
# TODO: BackupService::restore() itself is not yet implemented (see its
# docblock) — wire up `php artisan medinv:restore-backup "$MEDINV_RESTOREBACKUP"`
# here once it is, instead of only warning.
if [ -n "$MEDINV_RESTOREBACKUP" ]; then
    echo "WARNING: MEDINV_RESTOREBACKUP=$MEDINV_RESTOREBACKUP was set, but automatic restore-on-boot is not yet implemented."
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
