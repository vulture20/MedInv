# MedInv

**Version:** v0.5 · **Autor:** Thorsten Schröpel · [English version](README.md)

Eine webbasierte, responsive Anwendung zur zentralen Verwaltung physischer Mediensammlungen — Bücher, CDs und DVDs/Blu-rays — über mehrere unabhängige Bibliotheken hinweg, mit bibliotheksweiser Freigabe und rollenbasiertem Zugriff.

Das vollständige Konzept-/Anforderungsdokument liegt unter [`docs/medinv-briefing.md`](docs/medinv-briefing.md) — es ist die maßgebliche Quelle für das Verhalten der Anwendung; dieses README beschreibt, wie die Implementierung betrieben und gebaut wird.

## Über dieses Projekt

MedInv wurde per **Vibe-Coding** entwickelt: Das Anforderungsdokument (`docs/medinv-briefing.md`) wurde von einem Menschen verfasst, die gesamte Implementierung — Backend, Frontend, Docker-Deployment, Tests — wurde im Dialog mit diesem Konzept mittels [Claude Code](https://claude.com/claude-code) (Anthropic) generiert und iteriert, statt Zeile für Zeile von Hand geschrieben zu werden. Der Code wurde bei jedem Schritt tatsächlich ausgeführt und überprüft (Tests, Linter, laufende Container), sollte aber als KI-unterstütztes Gerüst zur Durchsicht und Weiterentwicklung verstanden werden, nicht als produktionserprobte Software.

## Technischer Stack

- **Backend:** PHP / Laravel 13 (`backend/`), REST-API unter `/api`, Sanctum SPA-Cookie-Authentifizierung, Eloquent als Mehr-Dialekt-Datenbankschicht (SQLite / MariaDB / PostgreSQL — wählbar über `MEDINV_DB_CONNECTION`).
- **Frontend:** React + TypeScript SPA (`frontend/`), gebaut mit Vite, `react-router` fürs Routing, `react-i18next` für deutsch-/englischsprachige Oberflächentexte.
- **Deployment:** ein einzelnes Docker-Image (`docker/Dockerfile`) betreibt nginx + php-fpm gemeinsam über supervisord, liefert die gebaute SPA aus und leitet `/api` + `/sanctum` an Laravel weiter. Das Datenbank-Backend läuft als separater Container/Dienst und ist nie Teil des Anwendungs-Images.

## Verzeichnisstruktur

```
backend/    Laravel-API — siehe backend/app/Domain/* für die Fachmodule (Libraries, Metadata, Capture, Backup, Search, Statistics, Security, Mail, ExportImport)
frontend/   React-SPA — siehe frontend/src/pages/* für je einen Ordner pro Seitenleisten-Bereich
docker/     Dockerfile, docker-compose.yml, entrypoint.sh, nginx.conf.template, supervisord.conf
docs/       docs/medinv-briefing.md — das Konzeptdokument, das all dem zugrunde liegt
```

## Lokale Entwicklung

Zwei getrennt laufende Dev-Server (sie kommunizieren über CORS + Sanctum-SPA-Cookies):

```bash
# Backend — http://localhost:8000 (oder --port=<eigener Port>)
cd backend
cp .env.example .env      # anschließend mindestens MEDINV_ADMINPASS setzen
php artisan key:generate
touch database/database.sqlite   # nur nötig bei der SQLite-Standardeinstellung
php artisan migrate
php artisan db:seed              # legt das Konto aus MEDINV_ADMINUSER/MEDINV_ADMINPASS an
composer install
php artisan serve

# Frontend — http://localhost:5173 (oder $MEDINV_PortWeb, siehe vite.config.ts)
cd frontend
cp .env.example .env
npm install
npm run dev
```

Backend-Tests / Linting:

```bash
cd backend
php artisan test        # PHPUnit
./vendor/bin/pint        # Code-Style (behebt automatisch; mit --test nur prüfen)
```

Frontend Typprüfung / Linting / Build:

```bash
cd frontend
npx tsc -b               # Typprüfung
npx oxlint                # Linting
npm run build             # Produktions-Build (frontend/dist)
```

## Betrieb mit Docker

```bash
cd docker
MEDINV_ADMINUSER=admin@example.com MEDINV_ADMINPASS='ChangeMe123!' docker compose up --build
```

Statt jede Variable auf der Kommandozeile zu übergeben, [`docker/.env.template`](docker/.env.template) nach `docker/.env` kopieren und ausfüllen — dort ist (zweisprachig, Deutsch + Englisch) jede von `docker-compose.yml` gelesene Variable dokumentiert; `docker compose` lädt `docker/.env` automatisch. Die resultierende `.env` (enthält echte Zugangsdaten) nicht einchecken — `docker/.gitignore` schließt sie bereits aus.

Erreichbar unter `http://localhost:8080` (SPA + same-origin API, unter `/api` und `/sanctum`) — auch `http://127.0.0.1:8080` funktioniert, beide sind als gültige Login-Origins vorkonfiguriert. Es gibt bewusst keinen separaten reinen API-Port: jeder API-Konsument, ob Browser oder nicht, nutzt denselben Port unter dem `/api`-Präfix. Der Port ist über eine Umgebungsvariable konfigurierbar:

```bash
MEDINV_PortWeb=9090 MEDINV_ADMINUSER=admin@example.com MEDINV_ADMINPASS='ChangeMe123!' docker compose up --build
```

`APP_KEY` muss nicht manuell gesetzt werden — er wird beim ersten Start generiert und im `storage`-Volume gespeichert, sodass er einen Container-Neustart übersteht. Ist diese Instanz nicht nur unter `localhost`/`127.0.0.1` erreichbar (z. B. hinter einem Reverse Proxy auf einer echten Domain), `MEDINV_URL` auf diese öffentliche URL setzen — sie wird als `APP_URL` verwendet und automatisch zusätzlich zu `localhost`/`127.0.0.1` als gültiger Login-Origin akzeptiert (`SANCTUM_STATEFUL_DOMAINS` muss nur noch explizit gesetzt werden, wenn diese Heuristik den eigenen Fall nicht abdeckt). Ohne das schlägt der Login mit einer allgemeinen Fehlermeldung fehl, obwohl die Zugangsdaten korrekt sind (Sanctum behandelt nur Anfragen von einem bekannten Origin als Browser-Sitzung):

```bash
MEDINV_URL=https://medinv.example.com MEDINV_ADMINUSER=admin@example.com MEDINV_ADMINPASS='ChangeMe123!' docker compose up --build
```

Nutzt standardmäßig SQLite (keine zusätzlichen Dienste nötig); um stattdessen MariaDB oder PostgreSQL zu verwenden, `MEDINV_DB_CONNECTION` (und die passenden `MEDINV_DB_*`-Variablen — alle Datenbank-Umgebungsvariablen sind mit `MEDINV_DB_` statt Laravels Standard-`DB_*`-Namen präfixiert) in `docker/.env` oder der Shell-Umgebung setzen und das passende Profil starten:

```bash
docker compose --profile mariadb up --build    # oder --profile postgres
```

Die vollständige Liste der `MEDINV_*`-Umgebungsvariablen (initialer Administrator-Account, Loglevel, vom Brute-Force-Schutz ausgenommener IP-Bereich, Backup-Wiederherstellung beim Start) steht in `docs/medinv-briefing.md`, Kapitel 16.

### Update auf eine neue Version

`docker/entrypoint.sh` führt bei jedem Containerstart `php artisan migrate --force` aus — ein neueres Image mit neuen Migrationsdateien wendet seine Datenbankänderungen also automatisch an, ohne manuellen Migrationsschritt beim Update. Unmittelbar davor läuft zusätzlich `php artisan medinv:pre-update-backup`, das ein Sicherheits-Backup erstellt, sobald auf einer bereits initialisierten Datenbank ausstehende Migrationen erkannt werden (bei einer Neuinstallation oder wenn sich nichts geändert hat, passiert nichts) — so gibt es bei einem problematischen Update einen Wiederherstellungspunkt.
