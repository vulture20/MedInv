# MedInv

**Author:** Thorsten Schröpel · [🇩🇪 Deutsche Version](README.de.md)

[![Docker Image](https://img.shields.io/badge/ghcr.io-vulture20%2Fmedinv-2496ED?logo=docker&logoColor=white)](https://github.com/vulture20/MedInv/pkgs/container/medinv)
[![Status](https://img.shields.io/badge/status-early%20beta-orange)](#-project-status)

MedInv is a self-hosted, responsive web app for centrally managing physical media collections — **books, CDs and DVDs/Blu-rays** — across multiple independent libraries, with per-library sharing and role-based access. Scan a barcode, let MedInv fetch the metadata and cover art for you, and keep track of what you own, what it's worth, and who it's shared with.

> ⚠️ **Early beta.** MedInv is under active development. Core features work and are covered by an automated test suite, but expect rough edges, and take backups before relying on it with real data. See [Project status](#-project-status) below.

The full concept/requirements document lives at [`docs/medinv-briefing.md`](docs/medinv-briefing.md) (German) — it's the source of truth for behavior; this README covers running and building the implementation.

## ✨ Features

### 📚 Media & libraries
- Three dedicated media types — books, CDs, DVDs/Blu-ray — each with its own fixed, purpose-built attribute set (no generic "extra field" clutter).
- Any number of independent **libraries**, each scoped to one media type, with per-library sharing to individual users at guest/user/admin-equivalent access levels.
- Manual entry, bulk field updates and bulk delete across selected items.

### 📷 Capture & metadata lookup
- **Camera-based barcode scanning** or manual entry — either way, MedInv looks up the item automatically instead of leaving you to type everything by hand.
- A pluggable metadata-provider system with real, working providers per media type: OpenLibrary, Google Books, Hardcover, Amazon and JPC (books); MusicBrainz, Discogs, Amazon and JPC (CDs); UPCMDB, Amazon and JPC (DVD/Blu-ray) — plus optional **AI-assisted lookup via Claude, ChatGPT or Gemini** for all three. Results from every enabled provider are merged field-by-field rather than picking one whole record.
- Cover art is downloaded and stored locally (with a generated thumbnail), never just hot-linked.
- A "no match" is never a dead end — items can always be captured manually, and metadata can be re-fetched later from the item's detail view.

### 🔎 Search & statistics
- Full-text search with genuine **typo-tolerant fuzzy matching**, tuned per database backend for the best available performance.
- Collection statistics: genre/language/year/publisher-artist-director distributions, and value growth over time — with automatic currency conversion so a mixed-currency library still adds up correctly.

### 🔐 Access control & sharing
- Three account levels (guest/user/admin) plus fine-grained, per-library sharing on top.
- Library ownership can be transferred; a user who owns libraries can't be deleted without first reassigning them.
- Optional **OpenID Connect / OAuth 2.0 login** (tested against Pocket ID) alongside classic email/password accounts, with configurable brute-force protection and a trusted-IP exemption range.

### 💾 Backup, export & import
- Scheduled automatic backups (interval- or cron-based) plus manual, on-demand backups, with configurable retention.
- A backup taken automatically before every update that changes the database schema, so a problematic update always has a restore point.
- Full instance restore, and per-library export/import between MedInv instances — both include cover images and go through the same conflict-resolution logic (rename/merge/overwrite/skip).

### 🌍 Internationalization & themes
- 18 bundled language packs (German, English, French, Spanish, Italian, Portuguese, Dutch, Polish, Russian, Ukrainian, Turkish, Japanese, Chinese, Norwegian, Swedish, Finnish, Icelandic) with an admin UI to add custom ones.
- Six bundled visual themes plus a custom CSS/template plugin system, switchable per instance.

## 🚧 Project status

MedInv was built with **vibe coding**: the requirements document (`docs/medinv-briefing.md`) was authored by a human, and the entire implementation — backend, frontend, Docker deployment, tests — was generated and iterated on with [Claude Code](https://claude.com/claude-code) (Anthropic) in conversation with that spec, rather than hand-written line by line. Code has been run and verified (tests, linters, live containers) at each step, and core CRUD, auth and permission handling are implemented and tested end to end — but several metadata providers are marked **Beta** and disabled by default (web-scraping- and LLM-based lookups in particular; see the plugin list in the admin area for details), and this should still be treated as an early-stage, AI-assisted project to review and build on, not battle-tested production software. Keep backups, and expect the occasional rough edge.

## 🐳 Quick start with Docker

MedInv ships as a single, self-contained Docker image published to the GitHub Container Registry — no build step required:

```bash
docker run -d \
  --name medinv \
  -p 8080:8080 \
  -e MEDINV_ADMINUSER=admin@example.com \
  -e MEDINV_ADMINPASS='ChangeMe123!' \
  -v medinv-storage:/var/www/backend/storage \
  --restart unless-stopped \
  ghcr.io/vulture20/medinv:latest
```

Then open **http://localhost:8080** and log in with the admin account you just set. That's the whole setup — MedInv uses an embedded SQLite database by default, so no extra database container is needed to get started.

The `medinv-storage` volume is what makes your data (database, covers, backups, the auto-generated app encryption key) survive container restarts and updates — **always mount it**, or everything resets on the next `docker run`.

### Key environment variables

| Variable | Required | Default | What it does |
|---|---|---|---|
| `MEDINV_ADMINUSER` | ✅ | — | Email address of the admin account created on first start. |
| `MEDINV_ADMINPASS` | ✅ | — | Password for that admin account. Only used on first start; change it later via the UI. |
| `MEDINV_PortWeb` | | `8080` | Port nginx listens on inside the container, serving both the UI and the API (under `/api`/`/sanctum`) — there's deliberately no separate API port. If you change this, update both `-p` and the value itself to match. |
| `MEDINV_URL` | | — | The public URL this instance is actually reachable at (e.g. behind a reverse proxy on a real domain). Required for logins to work from anywhere other than `localhost`/`127.0.0.1` — without it, login fails with a generic error even with correct credentials. |
| `MEDINV_DB_CONNECTION` | | `sqlite` | Database backend: `sqlite` (default, no extra services), `mariadb`, or `pgsql`. See `docker/docker-compose.yml` for a ready-made multi-container setup with `--profile mariadb`/`--profile postgres`. |
| `MEDINV_LOGLEVEL` | | `WARNING` | Initial log verbosity (`DEBUG`/`INFO`/`WARNING`/`ERROR`); changeable later in the admin area without a restart. |
| `MEDINV_TRUSTEDIP` | | — | IP or CIDR range exempt from the login brute-force throttle. |
| `MEDINV_RESTOREBACKUP` | | — | Filename of a backup to restore automatically on every container start — useful for demo/staging deployments that should always reset to a known state. |

This is the shortlist to get going. For the full reference — including all `MEDINV_DB_*` variables for MariaDB/PostgreSQL — see the bilingual [`docker/.env.template`](docker/.env.template) (works with `docker compose` too: copy it to `docker/.env`, fill it in, then `cd docker && docker compose up`) or `docs/medinv-briefing.md` chapter 16.

### Updating

Just pull the new image and recreate the container (`docker run` with the same flags, or `docker compose up -d --pull always`) — pending database migrations apply automatically on start, with a safety backup taken beforehand whenever there are any.

## 🧱 Tech stack

- **Backend:** PHP / Laravel 13 (`backend/`), REST API under `/api`, Sanctum SPA-cookie authentication, Eloquent as the multi-dialect database layer (SQLite / MariaDB / PostgreSQL — selectable via `MEDINV_DB_CONNECTION`).
- **Frontend:** React + TypeScript SPA (`frontend/`), built with Vite, `react-router` for routing, `react-i18next` for the bundled UI languages.
- **Deployment:** a single Docker image (`docker/Dockerfile`) runs nginx + php-fpm together via supervisord, serving the built SPA and proxying `/api` + `/sanctum` to Laravel. The database backend runs as a separate container/service, never bundled into the app image.

## 📁 Repository layout

```
backend/    Laravel API — see backend/app/Domain/* for the feature modules (Libraries, Metadata, Capture, Backup, Search, Statistics, Security, Mail, ExportImport)
frontend/   React SPA — see frontend/src/pages/* for one folder per sidebar section
docker/     Dockerfile, docker-compose.yml, entrypoint.sh, nginx.conf.template, supervisord.conf
docs/       docs/medinv-briefing.md — the concept document driving all of the above
```

## 🛠️ Local development

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
./vendor/bin/pint        # code style (auto-fixes; add --test to check without fixing)
```

Frontend type-check / lint / build:

```bash
cd frontend
npx tsc -b               # type-check
npx oxlint                # lint
npm run build             # production build (frontend/dist)
```

Building and running the Docker image locally (instead of pulling from ghcr.io) works the same way — see [`docker/.env.template`](docker/.env.template) and `docker compose up --build` from within `docker/`.
