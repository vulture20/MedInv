# MedInv

**Version:** v0.5 · **Author:** Thorsten Schröpel · [Deutsche Version](README.de.md)

A web-based, responsive application for centrally managing physical media collections — books, CDs and DVDs/Blu-rays — across multiple independent libraries with per-library sharing and role-based access.

The full concept/requirements document lives at [`docs/medinv-briefing.md`](docs/medinv-briefing.md) (German) — it's the source of truth for behavior; this README covers running and building the implementation.

## About this project

MedInv was built with **vibe coding**: the requirements document (`docs/medinv-briefing.md`) was authored by a human, and the entire implementation — backend, frontend, Docker deployment, tests — was generated and iterated on with [Claude Code](https://claude.com/claude-code) (Anthropic) in conversation with that spec, rather than hand-written line by line. Code has been run and verified (tests, linters, live containers) at each step, but treat this as an AI-assisted scaffold to review and build on, not battle-tested production software.

## Stack

- **Backend:** PHP / Laravel 13 (`backend/`), REST API under `/api`, Sanctum SPA-cookie authentication, Eloquent as the multi-dialect database layer (SQLite / MariaDB / PostgreSQL — selectable via `MEDINV_DB_CONNECTION`).
- **Frontend:** React + TypeScript SPA (`frontend/`), built with Vite, `react-router` for routing, `react-i18next` for German/English UI text.
- **Deployment:** a single Docker image (`docker/Dockerfile`) runs nginx + php-fpm together via supervisord, serving the built SPA and proxying `/api` + `/sanctum` to Laravel. The database backend runs as a separate container/service, never bundled into the app image.

## Repository layout

```
backend/    Laravel API — see backend/app/Domain/* for the feature modules (Libraries, Metadata, Capture, Backup, Search, Statistics, Security, Mail, ExportImport)
frontend/   React SPA — see frontend/src/pages/* for one folder per sidebar section
docker/     Dockerfile, docker-compose.yml, entrypoint.sh, nginx.conf.template, supervisord.conf
docs/       docs/medinv-briefing.md — the concept document driving all of the above
```

## Local development

Two dev servers, run separately (they talk to each other over CORS + Sanctum SPA cookies):

```bash
# Backend — http://localhost:8000 (or --port=<your port>)
cd backend
cp .env.example .env      # then set MEDINV_ADMINPASS at minimum
php artisan key:generate
touch database/database.sqlite   # only needed for the sqlite default
php artisan migrate
php artisan db:seed              # creates the MEDINV_ADMINUSER/MEDINV_ADMINPASS account
composer install
php artisan serve

# Frontend — http://localhost:5173 (or $MEDINV_PortWeb, see vite.config.ts)
cd frontend
cp .env.example .env
npm install
npm run dev
```

Backend tests / linting:

```bash
cd backend
php artisan test        # PHPUnit
./vendor/bin/pint        # code style (auto-fixes; add --test to check only)
```

Frontend type-check / lint / build:

```bash
cd frontend
npx tsc -b               # type-check
npx oxlint                # lint
npm run build             # production build (frontend/dist)
```

## Running with Docker

```bash
cd docker
MEDINV_ADMINUSER=admin@example.com MEDINV_ADMINPASS='ChangeMe123!' docker compose up --build
```

Instead of passing every variable on the command line, copy [`docker/.env.template`](docker/.env.template) to `docker/.env` and fill it in — it documents (bilingually, German + English) every variable `docker-compose.yml` reads, and `docker compose` loads `docker/.env` automatically. Don't commit the resulting `.env` (it holds real credentials); `docker/.gitignore` already excludes it.

Opens at `http://localhost:8080` (SPA + same-origin API, under `/api` and `/sanctum`) — `http://127.0.0.1:8080` works too, both are pre-configured as valid login origins. There is deliberately no separate API-only port: every API consumer, browser or otherwise, talks to this same port under the `/api` prefix. The port is configurable via env var:

```bash
MEDINV_PortWeb=9090 MEDINV_ADMINUSER=admin@example.com MEDINV_ADMINPASS='ChangeMe123!' docker compose up --build
```

`APP_KEY` doesn't need to be set manually — it's generated on first start and persisted in the `storage` volume so it survives container recreation. If this instance is reachable from somewhere other than `localhost`/`127.0.0.1` (e.g. behind a reverse proxy on a real domain), set `MEDINV_URL` to that public URL — it's used as `APP_URL` and automatically added to the accepted login origins alongside `localhost`/`127.0.0.1` (`SANCTUM_STATEFUL_DOMAINS`, only needed as an explicit override for setups that heuristic doesn't cover). Skipping this makes login fail with a generic error even though the credentials are correct (Sanctum only treats requests from a recognized origin as a browser session):

```bash
MEDINV_URL=https://medinv.example.com MEDINV_ADMINUSER=admin@example.com MEDINV_ADMINPASS='ChangeMe123!' docker compose up --build
```

Uses SQLite by default (no extra services); to use MariaDB or PostgreSQL instead, set `MEDINV_DB_CONNECTION` (and matching `MEDINV_DB_*` vars — all database env vars are `MEDINV_DB_`-prefixed, not Laravel's stock `DB_*` names) in `docker/.env` or the shell environment and start the matching profile:

```bash
docker compose --profile mariadb up --build    # or --profile postgres
```

See `docs/medinv-briefing.md` chapter 16 for the full list of `MEDINV_*` environment variables (initial admin account, loglevel, brute-force-exempt IP range, backup restore-on-boot).

### Updating to a new version

`docker/entrypoint.sh` runs `php artisan migrate --force` on every container start, so a newer image with new migration files applies its schema changes automatically — no manual migration step needed when updating. Immediately before doing so, it also runs `php artisan medinv:pre-update-backup`, which takes a safety-net backup whenever it detects pending migrations on an already-initialized database (it's a no-op on a fresh install or when nothing changed), so an update that turns out to be problematic has a restore point.
