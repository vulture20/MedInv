# MedInv

**Autor:** Thorsten Schröpel · [🇬🇧 English version](README.md)

[![Docker Image](https://img.shields.io/badge/ghcr.io-vulture20%2Fmedinv-2496ED?logo=docker&logoColor=white)](https://github.com/vulture20/MedInv/pkgs/container/medinv)
[![Docker Pulls](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fghcr-badge.elias.eu.org%2Fapi%2Fvulture20%2FMedInv%2Fmedinv&query=downloadCount&label=Docker%20Pulls&color=2496ED&logo=docker&logoColor=white)](https://github.com/vulture20/MedInv/pkgs/container/medinv)
[![Docker Image Build](https://github.com/vulture20/MedInv/actions/workflows/docker-image.yml/badge.svg)](https://github.com/vulture20/MedInv/actions/workflows/docker-image.yml)
[![Status](https://img.shields.io/badge/status-fr%C3%BChe%20Beta-orange)](#-projektstatus)
[![Lizenz: AGPL v3](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)

MedInv ist eine selbst gehostete, responsive Web-App zur zentralen Verwaltung physischer Mediensammlungen — **Bücher, CDs und DVDs/Blu-rays** — über mehrere unabhängige Bibliotheken hinweg, mit bibliotheksweiser Freigabe und rollenbasiertem Zugriff. Barcode scannen, MedInv holt Metadaten und Cover automatisch – und du behältst den Überblick, was du besitzt, was es wert ist und mit wem es geteilt wird.

> ⚠️ **Frühe Beta.** MedInv wird aktiv weiterentwickelt. Die Kernfunktionen laufen und sind durch eine automatisierte Testsuite abgedeckt, aber es gibt noch Ecken und Kanten – vor dem produktiven Einsatz mit echten Daten unbedingt Backups anlegen. Siehe [Projektstatus](#-projektstatus) weiter unten.

Das vollständige Konzept-/Anforderungsdokument liegt unter [`docs/medinv-briefing.md`](docs/medinv-briefing.md) — es ist die maßgebliche Quelle für das Verhalten der Anwendung; dieses README beschreibt, wie die Implementierung betrieben und gebaut wird.

## ✨ Funktionsumfang

### 📚 Medien & Bibliotheken
- Drei eigenständige Medientypen — Bücher, CDs, DVD/Blu-ray — jeweils mit festem, passgenauem Attributsatz (kein generisches "Extra-Feld"-Sammelsurium).
- Beliebig viele unabhängige **Bibliotheken**, je einem Medientyp zugeordnet, mit bibliotheksweiser Freigabe an einzelne Nutzer auf Gast-/Nutzer-/Admin-Zugriffsebene.
- Manuelle Erfassung, Massen-Feld-Updates und Massen-Löschung über ausgewählte Einträge hinweg.

### 📷 Erfassung & Metadaten-Abgleich
- **Kamerabasiertes Barcode-Scannen** oder manuelle Eingabe — in beiden Fällen sucht MedInv den Eintrag automatisch, statt alles per Hand eintippen zu lassen.
- Ein steckbares Metadaten-Provider-System mit echten, funktionierenden Anbietern je Medientyp: OpenLibrary, Google Books, Hardcover, Amazon und JPC (Bücher); MusicBrainz, Discogs, Amazon und JPC (CDs); UPCMDB, Amazon und JPC (DVD/Blu-ray) — dazu optional eine **KI-gestützte Suche via Claude, ChatGPT oder Gemini** für alle drei Medientypen. Ergebnisse aller aktivierten Provider werden feldweise zusammengeführt statt nur einen ganzen Datensatz zu übernehmen.
- Cover-Bilder werden heruntergeladen und lokal gespeichert (inklusive automatisch erzeugtem Thumbnail), nie nur verlinkt.
- "Kein Treffer" ist nie eine Sackgasse — Einträge lassen sich immer manuell anlegen, und Metadaten können später jederzeit aus der Detailansicht erneut abgerufen werden.

### 🔎 Suche & Statistik
- Volltextsuche mit echter **tippfehlertoleranter Fuzzy-Suche**, je nach Datenbank-Backend auf bestmögliche Performance abgestimmt.
- Statistiken zur Sammlung: Genre-/Sprach-/Jahres-/Verlags-Künstler-Regisseur-Verteilungen sowie Wertzuwachs über die Zeit — mit automatischer Währungsumrechnung, damit eine Bibliothek mit gemischten Währungen trotzdem korrekt summiert wird.

### 🔐 Zugriffskontrolle & Freigaben
- Drei Konto-Ebenen (Gast/Nutzer/Admin) plus feingranulare, bibliotheksweise Freigabe obendrauf.
- Der Besitz einer Bibliothek lässt sich übertragen; ein Nutzer, dem Bibliotheken gehören, kann nicht gelöscht werden, ohne diese vorher neu zuzuweisen.
- Optionaler **OpenID Connect / OAuth 2.0-Login** (getestet gegen Pocket ID) zusätzlich zu klassischen E-Mail/Passwort-Konten, mit konfigurierbarem Brute-Force-Schutz und einem davon ausgenommenen vertrauenswürdigen IP-Bereich.

### 💾 Backup, Export & Import
- Geplante automatische Backups (intervall- oder cron-basiert) sowie manuelle Backups auf Abruf, mit konfigurierbarer Aufbewahrung.
- Ein automatisches Sicherheits-Backup vor jedem Update, das das Datenbankschema ändert — ein problematisches Update hat so immer einen Wiederherstellungspunkt.
- Vollständige Instanz-Wiederherstellung sowie bibliotheksweiser Export/Import zwischen MedInv-Instanzen — beide inklusive Cover-Bildern und über dieselbe Konfliktauflösung (umbenennen/zusammenführen/überschreiben/überspringen).

### 🌍 Mehrsprachigkeit & Themes
- 18 mitgelieferte Sprachpakete (Deutsch, Englisch, Französisch, Spanisch, Italienisch, Portugiesisch, Niederländisch, Polnisch, Russisch, Ukrainisch, Türkisch, Japanisch, Chinesisch, Norwegisch, Schwedisch, Finnisch, Isländisch) mit Admin-Oberfläche für eigene Sprachpakete.
- Sechs mitgelieferte visuelle Themes plus ein Custom-CSS/Template-Plugin-System, je Instanz umschaltbar.

## 🚧 Projektstatus

MedInv wurde per **Vibe-Coding** entwickelt: Das Anforderungsdokument (`docs/medinv-briefing.md`) wurde von einem Menschen verfasst, die gesamte Implementierung — Backend, Frontend, Docker-Deployment, Tests — wurde im Dialog mit diesem Konzept mittels [Claude Code](https://claude.com/claude-code) (Anthropic) generiert und iteriert, statt Zeile für Zeile von Hand geschrieben zu werden. Der Code wurde bei jedem Schritt tatsächlich ausgeführt und überprüft (Tests, Linter, laufende Container), und Kern-CRUD, Authentifizierung sowie Berechtigungslogik sind durchgängig implementiert und getestet — einige Metadaten-Provider sind jedoch als **Beta** markiert und standardmäßig deaktiviert (insbesondere auf Web-Scraping oder LLMs basierende Abfragen; Details dazu in der Plugin-Liste im Admin-Bereich), und das Projekt sollte insgesamt noch als frühes, KI-unterstütztes Gerüst zur Durchsicht und Weiterentwicklung verstanden werden, nicht als produktionserprobte Software. Regelmäßig Backups anlegen und mit gelegentlichen Ecken und Kanten rechnen.

## 🐳 Schnellstart mit Docker

MedInv wird als ein einzelnes, in sich geschlossenes Docker-Image über die GitHub Container Registry veröffentlicht — kein eigener Build-Schritt nötig:

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

Danach **http://localhost:8080** öffnen und mit dem gerade gesetzten Admin-Konto einloggen. Das war's schon — MedInv nutzt standardmäßig eine eingebettete SQLite-Datenbank, für den Einstieg ist also kein zusätzlicher Datenbank-Container nötig.

Das Volume `medinv-storage` sorgt dafür, dass deine Daten (Datenbank, Cover, Backups, der automatisch erzeugte App-Verschlüsselungsschlüssel) einen Container-Neustart und Updates überstehen — **immer mounten**, sonst setzt sich beim nächsten `docker run` alles zurück.

`:latest` zeigt immer auf die zuletzt veröffentlichte Version (z. B. `:0.7` — die einzelnen Versions-Tags bleiben ebenfalls verfügbar, falls du eine konkrete Version festnageln willst). Lieber ungetestete, aktuellste Änderungen? `:nightly` folgt bei jedem Push dem aktuellen Stand des `main`-Branches, ohne jede Release-Prüfung dahinter.

### Wichtige Umgebungsvariablen

| Variable | Erforderlich | Standard | Bedeutung |
|---|---|---|---|
| `MEDINV_ADMINUSER` | ✅ | — | E-Mail-Adresse des Admin-Kontos, das beim ersten Start angelegt wird. |
| `MEDINV_ADMINPASS` | ✅ | — | Passwort für dieses Admin-Konto. Wird nur beim ersten Start verwendet; danach über die Oberfläche änderbar. |
| `MEDINV_PortWeb` | | `8080` | Port, auf dem nginx im Container lauscht — bedient sowohl die Oberfläche als auch die API (unter `/api`/`/sanctum`); es gibt bewusst keinen separaten API-Port. Bei Änderung sowohl `-p` als auch den Wert selbst anpassen. |
| `MEDINV_URL` | | — | Öffentliche URL, unter der diese Instanz tatsächlich erreichbar ist (z. B. hinter einem Reverse Proxy auf einer echten Domain). Nötig, damit der Login von woanders als `localhost`/`127.0.0.1` aus funktioniert — ohne diese Variable schlägt der Login mit einer allgemeinen Fehlermeldung fehl, obwohl die Zugangsdaten korrekt sind. |
| `MEDINV_DB_CONNECTION` | | `sqlite` | Datenbank-Backend: `sqlite` (Standard, keine zusätzlichen Dienste), `mariadb` oder `pgsql`. Ein fertiges Mehr-Container-Setup dafür liefert `docker/docker-compose.yml` mit `--profile mariadb`/`--profile postgres`. |
| `MEDINV_LOGLEVEL` | | `WARNING` | Anfänglicher Log-Umfang (`DEBUG`/`INFO`/`WARNING`/`ERROR`); später im Admin-Bereich ohne Neustart änderbar. |
| `MEDINV_TRUSTEDIP` | | — | IP oder CIDR-Bereich, der vom Brute-Force-Schutz beim Login ausgenommen ist. |
| `MEDINV_RESTOREBACKUP` | | — | Dateiname eines Backups, das bei jedem Containerstart automatisch wiederhergestellt werden soll — praktisch für Demo-/Staging-Umgebungen, die immer auf einen bekannten Zustand zurückgesetzt werden sollen. |

Das ist die Kurzliste für den Einstieg. Die vollständige Referenz — inklusive aller `MEDINV_DB_*`-Variablen für MariaDB/PostgreSQL — liefert die zweisprachige [`docker/.env.template`](docker/.env.template) (funktioniert auch mit `docker compose`: nach `docker/.env` kopieren, ausfüllen, dann `cd docker && docker compose up`) oder `docs/medinv-briefing.md`, Kapitel 16.

### Update

Einfach das neue Image ziehen und den Container neu anlegen (`docker run` mit denselben Parametern, oder `docker compose up -d --pull always`) — ausstehende Datenbank-Migrationen werden beim Start automatisch angewendet, zuvor jeweils mit einem Sicherheits-Backup abgesichert, falls welche anstehen.

## 🧱 Technischer Stack

- **Backend:** PHP / Laravel 13 (`backend/`), REST-API unter `/api`, Sanctum SPA-Cookie-Authentifizierung, Eloquent als Mehr-Dialekt-Datenbankschicht (SQLite / MariaDB / PostgreSQL — wählbar über `MEDINV_DB_CONNECTION`).
- **Frontend:** React + TypeScript SPA (`frontend/`), gebaut mit Vite, `react-router` fürs Routing, `react-i18next` für die mitgelieferten Oberflächensprachen.
- **Deployment:** ein einzelnes Docker-Image (`docker/Dockerfile`) betreibt nginx + php-fpm gemeinsam über supervisord, liefert die gebaute SPA aus und leitet `/api` + `/sanctum` an Laravel weiter. Das Datenbank-Backend läuft als separater Container/Dienst und ist nie Teil des Anwendungs-Images.

## 📁 Verzeichnisstruktur

```
backend/    Laravel-API — siehe backend/app/Domain/* für die Fachmodule (Libraries, Metadata, Capture, Backup, Search, Statistics, Security, Mail, ExportImport)
frontend/   React-SPA — siehe frontend/src/pages/* für je einen Ordner pro Seitenleisten-Bereich
docker/     Dockerfile, docker-compose.yml, entrypoint.sh, nginx.conf.template, supervisord.conf
docs/       docs/medinv-briefing.md — das Konzeptdokument, das all dem zugrunde liegt
```

## 🛠️ Lokale Entwicklung

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

Das Docker-Image lokal bauen und betreiben (statt von ghcr.io zu ziehen) funktioniert genauso — siehe [`docker/.env.template`](docker/.env.template) und `docker compose up --build` aus `docker/` heraus.

## ⚖️ Lizenz

MedInv steht unter der [GNU Affero General Public License v3.0 or later](LICENSE) (AGPL-3.0-or-later).

Kurz gefasst: Du darfst MedInv frei betreiben, verändern und selbst hosten. Die zusätzliche Pflicht, die AGPL im Vergleich zu einer gewöhnlichen GPL-Lizenz mit sich bringt: Wenn du eine **veränderte** Version von MedInv betreibst und anderen Nutzern über ein Netzwerk zugänglich machst (z. B. als gehosteten Dienst anbietest), musst du diesen Nutzern auch Zugriff auf deinen veränderten Quellcode geben — nicht nur Personen, denen du die Software direkt aushändigst. Der reine Betrieb einer unveränderten Kopie für dich selbst bringt über die üblichen Copyleft-Bedingungen hinaus keine zusätzliche Pflicht mit sich.
