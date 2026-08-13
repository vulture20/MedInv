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

RUN apk add --no-cache \
    nginx supervisor gettext sqlite-libs sqlite-dev libzip-dev icu-dev postgresql-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-install pdo_sqlite pdo_mysql pdo_pgsql bcmath zip intl opcache \
    && apk del $PHPIZE_DEPS

WORKDIR /var/www/backend

COPY --from=backend-deps /usr/bin/composer /usr/local/bin/composer
COPY backend/ ./
COPY --from=backend-deps /app/vendor ./vendor
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
ENTRYPOINT ["entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]
