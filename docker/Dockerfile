# syntax=docker/dockerfile:1

# --- Stage 1: build the React SPA ------------------------------------------
FROM node:20-alpine AS frontend-build
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
# Same-origin in production (nginx below serves both SPA and /api on one
# port), so no base URL / CORS configuration is needed at runtime.
ENV VITE_API_BASE_URL=""
RUN npm run build

# --- Stage 2: PHP dependencies (separate from app code for layer caching) --
FROM composer:2 AS backend-deps
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# --- Stage 3: application image ---------------------------------------------
FROM php:8.4-fpm-alpine AS app

# GitHub issue #47: the GD extension (imagecreatefromstring() and friends,
# CoverDownloadService::generateThumbnail()) used to be entirely missing
# from this image — every single thumbnail-generation attempt, in every
# Docker deployment, failed with "Call to undefined function ...
# imagecreatefromstring()", silently caught and merely logged, with the app
# falling back to serving the full-size cover instead. libjpeg-turbo-dev/
# libpng-dev/freetype-dev/libwebp-dev are GD's build-time dependencies
# (dev packages needed only while compiling the extension, hence still
# covered by `apk del $PHPIZE_DEPS` below removing the whole build
# toolchain afterwards); the shared libraries GD actually links against at
# runtime come along as those packages' automatic non-dev dependencies and
# stay installed. `php -m` after install is a build-time sanity check, not
# a no-op — a future edit to this block that drops `gd` again fails the
# image build immediately instead of shipping a silently broken image
# again.
RUN apk add --no-cache \
    nginx supervisor gettext sqlite-libs sqlite-dev libzip-dev icu-dev postgresql-dev \
    libjpeg-turbo-dev libpng-dev freetype-dev libwebp-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
    && docker-php-ext-install pdo_sqlite pdo_mysql pdo_pgsql bcmath zip intl opcache gd \
    && apk del $PHPIZE_DEPS \
    && php -m | grep -qx gd

WORKDIR /var/www/backend

COPY --from=backend-deps /usr/bin/composer /usr/local/bin/composer
COPY backend/ ./
COPY --from=backend-deps /app/vendor ./vendor

# Bundled language packs (BundledLanguagePackRegistry, config('medinv.
# languagepacks_path')) — copied to the same relative position as in the
# repo (a sibling of backend/, i.e. WORKDIR/../languagepacks =
# /var/www/languagepacks) so base_path('../languagepacks') resolves
# identically here and in local dev, with no environment-specific path logic.
COPY languagepacks/ /var/www/languagepacks/

# Bundled UI templates (BundledTemplateRegistry, config('medinv.
# templates_path')) — same relative-position reasoning as languagepacks/
# just above.
COPY templates/ /var/www/templates/

# Bundled UI locale files (App\Domain\Languages\Translator, config('medinv.
# locales_path'), GitHub issue #113 — PDF exports rendering in the
# requesting user's language) — copied to the exact same nested path it
# has in the repo (not flattened to a new top-level directory, unlike
# languagepacks/templates/ above) so base_path('../frontend/src/i18n/
# locales') resolves identically here and in local dev. The frontend-build
# stage above only ever produces a minified dist/ bundle with no raw JSON
# left in it, so these source files wouldn't otherwise exist in this image
# at all.
COPY frontend/src/i18n/locales/ /var/www/frontend/src/i18n/locales/
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/app/private/backups storage/database bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY --from=frontend-build /app/dist /var/www/spa

# Rendered into nginx.conf at container start (entrypoint.sh, via envsubst)
# once MEDINV_PortWeb is known — nginx itself can't read environment
# variables.
COPY docker/nginx.conf.template /etc/nginx/nginx.conf.template
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Persisted outside the image (see docker/docker-compose.yml volumes):
# backups (9.2/9.3), logs, app_key, and the sqlite database file when that
# backend is chosen (storage/database/, see entrypoint.sh — GitHub issue
# #25 on why this deliberately isn't also /var/www/backend/database, which
# holds application code (migrations/seeders/factories) that must stay
# part of the image and pick up every update, not get frozen in a volume).
VOLUME ["/var/www/backend/storage"]

# Documented default for MEDINV_PortWeb (entrypoint.sh) — EXPOSE is metadata
# only; the actual listen port is only known at container start, and
# docker-compose.yml maps whatever is really configured.
EXPOSE 8080

# Hits Laravel's built-in /up route (bootstrap/app.php, proxied to php-fpm
# by nginx.conf.template) rather than / (would just report the static SPA
# healthy even with php-fpm down) or /api/version (a plain config() read —
# works, but /up is the framework's own dedicated liveness signal, so this
# uses that instead of repurposing an unrelated endpoint). Deliberately
# stays a pure liveness check, not a readiness check that also pings the
# database — mariadb/postgres already have their own connectivity-checking
# healthcheck below in docker-compose.yml, and a transient DB hiccup
# shouldn't also flip *this* container to unhealthy. curl (not busybox
# wget) since it's already present in the base php:8.4-fpm-alpine image
# (confirmed, no extra apk install needed) and -f turns any non-2xx status
# into a nonzero exit for HEALTHCHECK to act on. Shell form (no exec-array
# brackets) so ${MEDINV_PortWeb} expands via /bin/sh -c against the
# container's real environment, set by entrypoint.sh below before
# supervisord ever starts. start-period=40s covers entrypoint.sh's
# migrate/seed/restore work, which all runs — and must finish — before
# nginx/php-fpm even start listening.
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${MEDINV_PortWeb:-8080}/up" || exit 1

ENTRYPOINT ["entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]
