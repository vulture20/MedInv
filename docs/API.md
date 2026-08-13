# MedInv API-Dokumentation

Diese Datei dokumentiert die HTTP-API des MedInv-Backends (`backend/`, Laravel 13). Sie ist eine technische Referenz für Endpunkte, Request-/Response-Formate und Fehlercodes. Für das fachliche Verhalten der Anwendung ist [`docs/medinv-briefing.md`](medinv-briefing.md) maßgeblich — an passenden Stellen wird auf dessen Kapitel verwiesen.

> Stand: `backend/config/medinv.php` — Version siehe `GET /api/version`. Diese Doku wurde am 2026‑08‑12 aus dem aktuellen Code (Routen, Controller, Domain-Services, Models, Migrationen) erstellt und nicht separat freihändig ergänzt; wo etwas laut `CLAUDE.md` noch nicht fertig implementiert ist ("Known‑incomplete areas"), ist das unten explizit vermerkt.

## Inhalt

- [Grundlagen](#grundlagen)
- [Authentifizierung](#authentifizierung)
- [Fehlerformat](#fehlerformat)
- [Öffentliche Endpunkte](#öffentliche-endpunkte)
- [Auth & eigenes Konto](#auth--eigenes-konto)
- [Suche & Statistik](#suche--statistik)
- [Bibliotheken](#bibliotheken)
- [Medien-Items](#medien-items)
- [Erfassung / Bulk-Import](#erfassung--bulk-import)
- [Metadaten-Abgleich](#metadaten-abgleich)
- [Administration: Benutzer](#administration-benutzer)
- [Administration: Export/Import](#administration-exportimport)
- [Administration: Backups](#administration-backups)
- [Administration: Systemeinstellungen](#administration-systemeinstellungen)
- [Datenmodelle (Referenz)](#datenmodelle-referenz)

## Grundlagen

- **Basis-URL**: Im Docker-Image same-origin unter `/api/*` (+`/sanctum/*`), siehe `docker/nginx.conf.template`. In lokaler Entwicklung läuft das Backend separat (`php artisan serve`, Standard-Port 8000), der Frontend-Devserver auf 5173 und spricht das Backend über `VITE_API_BASE_URL` an.
- **Format**: Alle Endpunkte senden/empfangen JSON (`Content-Type: application/json`), außer Datei-Uploads (`multipart/form-data`: Textdatei-Import, Backup-/Export-Import) und dem Backup-Download (Binärdatei).
- **Antwortcodes**: Standard-HTTP-Codes; erfolgreiche `POST`-Erstellungen liefern `201`, erfolgreiche Löschungen `204 No Content`.

## Authentifizierung

MedInv verwendet **Laravel Sanctum SPA-Session-Auth** (Cookie-/Session-basiert), **keine** Bearer-Tokens.

1. Zuerst `GET /sanctum/csrf-cookie` aufrufen, um das `XSRF-TOKEN`-Cookie zu setzen.
2. Den Wert dieses Cookies als `X-XSRF-TOKEN`-Header (URL-decodiert) auf allen folgenden state-changing Requests mitschicken.
3. `POST /api/login` mit `email`/`password` aufrufen — bei Erfolg wird eine Session angelegt und das Session-Cookie gesetzt.
4. Alle weiteren Requests laufen über das Session-Cookie; kein `Authorization`-Header nötig.

Damit ein Request als "stateful" behandelt wird (Session + CSRF-Middleware greifen), muss sein `Origin`/`Referer`-Header zu einem Eintrag in `SANCTUM_STATEFUL_DOMAINS` passen (`backend/config/sanctum.php`). Ein Mismatch schlägt **ohne** aussagekräftige Fehlermeldung fehl (`419`/`500`/Session-Fehler) — beim Testen mit `curl` immer einen passenden `Origin`-Header senden.

### Globale Benutzer-Level (briefing 4.2)

Jeder Benutzer hat genau ein `level`: `guest` | `user` | `admin`. Es steuert grob den Funktionsumfang:

| Level | Kann |
|---|---|
| `guest` | Nur lesen (freigegebene/geteilte Bibliotheken), keine eigenen Bibliotheken/Items anlegen |
| `user` | Eigene Bibliotheken anlegen/verwalten, Items erfassen, sofern Zugriff |
| `admin` | Alles, inkl. Benutzerverwaltung, Export/Import, Backups, Systemeinstellungen; sieht/verwaltet jede Bibliothek unabhängig von Freigaben |

Zusätzlich existiert pro Bibliothek eine granularere Sichtbarkeits-/Schreibregel (briefing 4.3), zentral implementiert in `App\Domain\Libraries\LibraryAccessService` und von jedem Endpunkt unten referenziert:

- **Lesen** (`canRead`): Admin **oder** Besitzer **oder** eine passende `LibraryShare` (`scope=all_users` für jeden `user`-Level-Account, `scope=guest` für jeden `guest`-Level-Account, `scope=user` für genau den referenzierten `user_id`).
- **Schreiben** (`canWrite`): Admin **oder** Besitzer. Freigaben gewähren nie Schreibzugriff.
- Nicht freigegebene Bibliotheken sind für Nicht-Besitzer/Nicht-Admins **weder sichtbar noch auffindbar** — das gilt auch für die globale Suche (`GET /api/search`) und Statistik.

### Middleware pro Route

- `auth:sanctum` — angemeldete Session erforderlich (alle Endpunkte außer den unten gelisteten öffentlichen).
- `active` (`EnsureUserIsActive`) — deaktivierte Konten (`is_active=false`) werden mit `403 {"message":"Account is deactivated."}` abgewiesen und serverseitig ausgeloggt.
- `level:<level1>,<level2>,...` (`EnsureUserHasLevel`) — verlangt eines der genannten globalen Level, sonst `403 {"message":"Forbidden."}`.

## Fehlerformat

Zwei Fehlerformen kommen vor:

1. **Laravel-Validierungsfehler** (`422`, Standardformat bei `$request->validate()`-Fehlschlägen):
   ```json
   {
     "message": "The email field is required.",
     "errors": { "email": ["The email field is required."] }
   }
   ```
2. **Anwendungsfehler mit `error_code`** — für Fehler, die das Frontend gezielt behandeln/übersetzen muss (briefing-Konvention aus `CLAUDE.md`: *"API errors carry a machine-readable `error_code`, not just prose"*), z. B.:
   ```json
   { "error_code": "invalid_credentials", "message": "Invalid credentials." }
   ```
   Bekannte `error_code`-Werte sind bei den jeweiligen Endpunkten unten vermerkt.

Sonstige Fehler: `401` (nicht angemeldet), `403` (`{"message":"Forbidden."}` bzw. `{"message":"Account is deactivated."}`), `404` (Route-Model-Binding schlägt fehl), `409` (Duplikat-EAN, siehe Medien-Items), `503` (Passwort-Reset bei nicht erreichbarem Mailserver).

---

## Öffentliche Endpunkte

Ohne Anmeldung erreichbar (briefing 11.1: "login screen is the only thing reachable unauthenticated").

### `GET /api/version`

Liefert Name und Version der Anwendung (Quelle: `config/medinv.php`), wird auf dem Login-Screen und im App-Footer angezeigt.

**Response `200`**
```json
{ "name": "MedInv", "version": "v0.5" }
```

### `POST /api/login`

Meldet einen Benutzer an (Sanctum-Session, `remember=true`). Unterliegt dem Brute-Force-Schutz (briefing 12.4).

**Request**
```json
{ "email": "user@example.com", "password": "secret" }
```
| Feld | Typ | Pflicht |
|---|---|---|
| `email` | string, E-Mail | ✓ |
| `password` | string | ✓ |

**Response `200`**
```json
{
  "user": { "id": 1, "name": "Jane", "email": "jane@example.com", "level": "user", "is_active": true, "is_protected": false, "preferred_language": "de", "preferred_template": "light", "created_at": "...", "updated_at": "..." },
  "mail_server_healthy": true
}
```

**Fehler `422`** — `error_code`:
| `error_code` | Bedeutung |
|---|---|
| `account_locked` | Zu viele Fehlversuche (briefing 12.4) — Konto temporär gesperrt |
| `invalid_credentials` | E-Mail/Passwort falsch |
| `account_deactivated` | Konto ist deaktiviert (`is_active=false`) |

### `POST /api/password/email`

Startet den Self-Service-Passwort-Reset per E-Mail (briefing 12.3). Antwort ist bewusst generisch, um kein Enumerieren gültiger Konten zu erlauben.

**Request**: `{ "email": "user@example.com" }`

**Response `200`**: `{ "message": "If that address exists, a reset link has been sent." }`

**Fehler `503`**: wenn der Mailserver laut `MailStatusService` nicht erreichbar/konfiguriert ist — `{"message": "Password reset is unavailable: mail server is not configured or unreachable."}`. Das Frontend blendet den Einstiegspunkt in diesem Fall bereits über `mail_server_healthy` aus.

### `POST /api/password/reset`

Schließt den Passwort-Reset ab.

**Request**
```json
{ "token": "...", "email": "user@example.com", "password": "NewPass123!", "password_confirmation": "NewPass123!" }
```
`password` muss `App\Rules\MedInvPasswordPolicy` erfüllen (briefing 12.1): mindestens 10 Zeichen, je mind. ein Groß-, Kleinbuchstabe, eine Ziffer, ein Sonderzeichen.

**Response `200`**: `{ "message": "Password has been reset." }`

**Fehler**: `422` (Validierung, z. B. ungültiges/abgelaufenes Token → Feld `email`), `503` (Mailserver nicht erreichbar, wie oben).

---

Alle folgenden Endpunkte erfordern eine aktive Session (`auth:sanctum` + `active`).

## Auth & eigenes Konto

### `POST /api/logout`

Beendet die Session (invalidiert Session, regeneriert CSRF-Token).

**Response**: `204 No Content`

### `GET /api/me`

Liefert den aktuell angemeldeten Benutzer plus Mailserver-Status.

**Response `200`**
```json
{ "user": { "...": "wie bei /api/login" }, "mail_server_healthy": true }
```

### `PUT /api/me/settings`

Aktualisiert die eigenen UI-Präferenzen (briefing 4.1/10./11.4).

**Request** (beide Felder optional)
```json
{ "preferred_language": "de", "preferred_template": "dark" }
```
| Feld | Typ | Regeln |
|---|---|---|
| `preferred_language` | string | max. 10 Zeichen |
| `preferred_template` | string | eines von `light`, `dark` |

**Response `200`**: aktualisiertes User-Objekt.

> **Bekannt unvollständig**: `preferred_template` ist hart auf `light`/`dark` begrenzt — installierbare Zusatz-Templates (briefing 10./11.4) existieren noch nicht.

## Suche & Statistik

Verfügbar für alle Level (guest/user/admin), jeweils skopiert auf sichtbare Bibliotheken (`LibraryAccessService`).

### `GET /api/search`

Volltextsuche über alle drei Medientypen hinweg (briefing 13.), durchsucht typspezifische Textspalten (Titel, Beschreibung, Autoren/Künstler/Regie, Genre, Sprache, EAN, ISBN, …).

**Query-Parameter**
| Parameter | Typ | Pflicht | Beschreibung |
|---|---|---|---|
| `query` | string, min. 1 Zeichen | ✓ | Suchbegriff |
| `fuzzy` | boolean | – | siehe Hinweis unten |

**Response `200`**: flache JSON-Liste von Treffern, jeweils das Medien-Item inkl. geladener `library`-Relation (`id`, `name`, `media_type`):
```json
[
  { "id": 12, "title": "...", "ean": "...", "...": "...", "library": { "id": 3, "name": "Romane", "media_type": "book" } }
]
```

> **Bekannt unvollständig**: `fuzzy=true` lockert aktuell nur die Groß-/Kleinschreibung — es gibt noch keine echte tippfehlertolerante Suche.

### `GET /api/statistics`

Bestandsstatistik je sichtbarer Bibliothek (briefing 14.).

**Response `200`**
```json
[
  { "library_id": 3, "library_name": "Romane", "media_type": "book", "item_count": 42, "total_value": "419.58" }
]
```

> **Bekannt unvollständig**: Genre-/Sprach-/Jahr-/Verleger- etc. Verteilungen aus briefing 14. sind noch nicht implementiert — nur Anzahl und Gesamtwert pro Bibliothek.

## Bibliotheken

Lesen (`index`/`show`) für jeden angemeldeten Benutzer erreichbar, aber pro Bibliothek durch `LibraryAccessService::canRead()` gefiltert/geprüft. Schreibende Endpunkte zusätzlich hinter `level:user,admin` (Gäste kommen dort nie an) **und** `canWrite()` (Besitzer oder Admin).

### `GET /api/libraries`

Liste aller für den Benutzer sichtbaren Bibliotheken, inkl. `owner` (`id`, `name`).

**Response `200`**: Array von Library-Objekten (siehe [Datenmodelle](#library)).

### `GET /api/libraries/{library}`

Details einer Bibliothek inkl. `owner` und `shares.user` (`id`, `name`, `email`).

**Fehler**: `403` wenn nicht sichtbar.

### `POST /api/libraries` *(level: user, admin)*

Legt eine neue Bibliothek an; der aufrufende Benutzer wird automatisch `owner`.

**Request**
```json
{ "name": "Meine Romane", "description": "optional", "media_type": "book" }
```
| Feld | Typ | Pflicht |
|---|---|---|
| `name` | string, max. 255 | ✓ |
| `description` | string, nullable | – |
| `media_type` | `book` \| `cd` \| `dvd_bluray` | ✓ |

**Response `201`**: das neue Library-Objekt.

> `media_type` ist nach dem Anlegen **nicht mehr änderbar** (briefing 5.) — es gibt bewusst keinen Update-Pfad dafür.

### `PUT /api/libraries/{library}` *(level: user, admin, + canWrite)*

**Request** (beide optional): `{ "name": "...", "description": "..." }`

**Response `200`**: aktualisiertes Library-Objekt. **Fehler `403`** wenn kein Schreibzugriff.

### `DELETE /api/libraries/{library}` *(level: user, admin, + canWrite)*

**Response**: `204 No Content`. **Fehler `403`** wenn kein Schreibzugriff.

### `PUT /api/libraries/{library}/shares` *(level: user, admin, + canWrite)*

Ersetzt die komplette Freigabeliste einer Bibliothek (briefing 4.3).

**Request**
```json
{
  "shares": [
    { "scope": "all_users" },
    { "scope": "guest" },
    { "scope": "user", "user_id": 7 }
  ]
}
```
| Feld | Typ | Regeln |
|---|---|---|
| `shares` | array | – |
| `shares[].scope` | `guest` \| `all_users` \| `user` | Pflicht, sobald `shares` gesetzt ist |
| `shares[].user_id` | integer, muss existierender User sein | Pflicht bei `scope=user` |

**Response `200`**: Library mit geladenen `shares.user` (`id`, `name`, `email`).

**Fehler `422`**: `{"errors": {"shares": ["user_id is required for scope=user."]}}`, falls `scope=user` ohne `user_id`.

## Medien-Items

Alle unter `/api/libraries/{library}/items` verschachtelt, hinter `level:user,admin`. Lesen prüft `canRead`, Schreiben `canWrite` (jeweils `403` sonst). Ein Controller für alle drei Medientypen — das erwartete Feldschema hängt vom `media_type` der Bibliothek ab (briefing 6.).

### `GET /api/libraries/{library}/items`

Paginierte Liste der Items der Bibliothek (Laravel-Standard-Paginator).

**Query-Parameter**: `per_page` (integer, Default 50).

**Response `200`**
```json
{
  "data": [ { "id": 1, "title": "...", "...": "..." } ],
  "current_page": 1, "last_page": 3, "per_page": 50, "total": 120, "...": "weitere Paginator-Felder"
}
```

### `GET /api/libraries/{library}/items/{item}`

**Response `200`**: einzelnes Medien-Item. **Fehler `404`**, falls nicht in dieser Bibliothek.

### `POST /api/libraries/{library}/items` — Einzelerfassung (briefing 7.1)

Feldset abhängig von `library.media_type`, siehe Tabellen unten. Alle drei Typen teilen sich: `title` (Pflicht, string, max. 255), `ean` (Pflicht, string, max. 13), `cover_path` (nullable string), `description` (nullable string), `release_date` (nullable date), `price` (nullable numeric).

**`media_type = book`** — zusätzlich: `authors`, `format`, `genre` (string, nullable), `page_count` (integer, nullable), `language` (string, max. 10, nullable), `publisher` (string, nullable), `isbn10` (max. 10, nullable), `isbn13` (max. 13, nullable).

**`media_type = cd`** — zusätzlich: `artist`, `medium`, `asin` (string, nullable), `disc_count` (integer, min. 1, nullable).

**`media_type = dvd_bluray`** — zusätzlich: `medium` (string, nullable), `disc_count` (integer, min. 1, nullable), `runtime_minutes` (integer, nullable), `languages`, `cast`, `director` (string, nullable), `production_year` (integer, nullable).

**Response `201`**: das neue Item.

**Fehler `409`** — Duplikat-EAN innerhalb derselben Bibliothek (briefing 5.1: gleiches EAN über Bibliotheken hinweg erlaubt, innerhalb einer Bibliothek strikt abgelehnt, keine automatische Bestandserhöhung):
```json
{ "message": "A media item with EAN 978-3-16-148410-0 already exists in this library.", "ean": "978-3-16-148410-0" }
```

### `PUT /api/libraries/{library}/items/{item}`

Gleiches Feldset wie beim Anlegen, aber alle Felder `sometimes` (nur mitgeschickte Felder werden aktualisiert) und **`ean` ist von Updates ausgeschlossen** — eine EAN-Änderung erfordert Löschen + Neuanlegen, damit die Duplikat-Prüfung nicht umgangen werden kann.

**Response `200`**: aktualisiertes Item.

### `DELETE /api/libraries/{library}/items/{item}`

**Response**: `204 No Content`.

## Erfassung / Bulk-Import

Briefing 7.2. Sowohl Hardware-Barcodescanner als auch (clientseitig dekodiertes) Kamera-Scanning senden einzelne Codes an denselben `scan()`-Endpunkt — aus Backend-Sicht nicht unterscheidbar. Beide Endpunkte liegen unter `level:user,admin` + `canWrite`.

### `POST /api/libraries/{library}/capture/scan`

Verarbeitet einen einzelnen gescannten/eingegebenen EAN: Duplikat-Prüfung zuerst (briefing 5.1), danach automatischer Metadaten-Abgleich (8.3).

**Request**: `{ "ean": "978-3-16-148410-0" }`

**Response `200`** — eines von:
```json
{ "status": "duplicate", "ean": "..." }
```
```json
{ "status": "no_match", "ean": "...", "candidates": [] }
```
```json
{
  "status": "candidates",
  "ean": "...",
  "candidates": [
    { "provider_key": "open_library", "source_id": "OL123M", "attributes": { "title": "...", "ean": "...", "...": "..." }, "cover_urls": ["https://..."] }
  ]
}
```
`attributes` verwendet dieselben Schlüssel wie das Feldschema des Ziel-Medientyps (siehe oben) und kann direkt an `POST .../metadata/import` weitergereicht werden.

### `POST /api/libraries/{library}/capture/textfile`

Import einer Textdatei mit einem EAN pro Zeile (dritter Erfassungsweg aus 7.2).

**Request**: `multipart/form-data` mit Feld `file` (Pflicht, Datei).

**Response `200`**: Array von Ergebnissen im gleichen Format wie oben, ein Eintrag pro (nicht-leerer) Zeile:
```json
[ { "status": "candidates", "ean": "...", "candidates": [ "..." ] }, { "status": "duplicate", "ean": "..." } ]
```

## Metadaten-Abgleich

Briefing 8. `GET /api/metadata/plugins` ist für jeden angemeldeten Benutzer erreichbar; die restlichen unter `level:user,admin` + `canWrite`.

### `GET /api/metadata/plugins`

Listet registrierte Metadaten-Provider-Plugins (briefing 8.1/8.2), optional gefiltert.

**Query-Parameter**: `media_type` (optional: `book` \| `cd` \| `dvd_bluray`).

**Response `200`**: Array von MetadataPlugin-Objekten, sortiert nach `priority` (siehe [Datenmodelle](#metadataplugin)).

> Aktuell ist je Medientyp nur **ein** Beispiel-Provider implementiert: OpenLibrary (Buch), MusicBrainz (CD), UPCMDB (DVD/Blu-ray). Die übrigen aus briefing 8.2 (Hardcover, Amazon, Google Books, Discogs, Emunation.ch) sind `TODO`s in `MetadataProviderRegistry`.

### `GET /api/libraries/{library}/metadata/search`

Manuelle Metadatensuche (statt Scan) über alle für den Medientyp der Bibliothek aktivierten Provider.

**Query-Parameter**: `query` (string, Pflicht).

**Response `200`**: Array von Kandidaten, gleiches Format wie `candidates` oben.

### `POST /api/libraries/{library}/metadata/import`

Bestätigt einen zuvor gelieferten Kandidaten und legt daraus ein Medien-Item an (briefing 8.3, Schritte 4–6). Alternativ kann der Client alle Kandidaten ablehnen und direkt `POST .../items` aufrufen — dieser Endpunkt ist rein optional.

**Request**
```json
{ "attributes": { "ean": "978-3-16-148410-0", "title": "...", "...": "..." }, "cover_url": "https://..." }
```
| Feld | Typ | Pflicht |
|---|---|---|
| `attributes` | object | ✓ |
| `attributes.ean` | string | ✓ |
| `cover_url` | string, nullable | – |

**Response `201`**: das neue Item.

**Fehler `409`**: Duplikat-EAN, gleiches Format wie bei `POST .../items`.

> **Bekannt unvollständig**: `cover_url` wird aktuell nicht heruntergeladen/gespeichert (siehe `TODO` in `MetadataController::import()`, briefing 8.3 Schritt 5).

---

Alle folgenden Endpunkte liegen unter `/api/admin/*` und erfordern `level:admin`.

## Administration: Benutzer

Briefing 4.1. Konten werden ausschließlich von Administratoren angelegt (kein Self-Signup). Der initiale, aus `MEDINV_ADMINUSER`/`MEDINV_ADMINPASS` erzeugte Account ist `is_protected` und gegen Bearbeitung, Deaktivierung und Löschung geschützt, damit eine Installation nie ohne funktionierenden Admin-Zugang enden kann.

### `GET /api/admin/users`

**Response `200`**: alle Benutzer, sortiert nach `name`.

### `POST /api/admin/users`

**Request**
```json
{ "name": "Jane Doe", "email": "jane@example.com", "password": "SuperSecret1!", "level": "user" }
```
| Feld | Typ | Regeln |
|---|---|---|
| `name` | string, max. 255 | Pflicht |
| `email` | string, E-Mail | Pflicht, eindeutig |
| `password` | string | Pflicht, `MedInvPasswordPolicy` (min. 10 Zeichen, Groß-/Kleinbuchstabe, Ziffer, Sonderzeichen) |
| `level` | `guest` \| `user` \| `admin` | Pflicht |

**Response `201`**: neuer Benutzer.

### `PUT /api/admin/users/{user}`

**Request** (alle Felder optional): `{ "name": "...", "email": "...", "level": "admin" }`

**Response `200`**: aktualisierter Benutzer. **Fehler `422`** mit `error_code: "protected_account"`, falls Ziel der geschützte Admin ist:
```json
{ "error_code": "protected_account", "message": "This account cannot be edited." }
```

### `DELETE /api/admin/users/{user}`

Löscht den Account endgültig (Unterschied zu `deactivate`, siehe unten). **Response**: `204 No Content`. **Fehler `422`** (`protected_account`) beim geschützten Admin.

### `POST /api/admin/users/{user}/deactivate`

Deaktiviert statt zu löschen — Login wird unmöglich, Historie/Eigentümerschaft bleibt erhalten (briefing 4.1).

**Response `200`**: aktualisierter Benutzer (`is_active: false`). **Fehler `422`** (`protected_account`) beim geschützten Admin.

### `POST /api/admin/users/{user}/reactivate`

**Response `200`**: aktualisierter Benutzer (`is_active: true`).

## Administration: Export/Import

Briefing 9.1. Admin-only, weil Export die Freigabe-Prüfung pro Bibliothek bewusst umgeht ("alle" muss wirklich alle Bibliotheken umfassen, nicht nur die dem Admin freigegebenen).

### `POST /api/admin/export`

**Request** (optional): `{ "library_ids": [1, 2, 3] }` — leer/fehlend bedeutet "alle" Bibliotheken.

**Response `200`** (mit `Content-Disposition: attachment; filename="medinv-export-<Zeitstempel>.json"`):
```json
{
  "format_version": 1,
  "exported_at": "2026-08-12T10:00:00+00:00",
  "system_settings": { "mail.host": "smtp.example.com", "backup.interval_mode": "daily", "...": "..." },
  "libraries": [
    {
      "name": "Romane", "description": "...", "media_type": "book",
      "shares": [ { "scope": "all_users", "user_email": null } ],
      "items": [ { "title": "...", "ean": "...", "...": "..." } ]
    }
  ]
}
```
`system_settings` is a flat `key => value` dump of the whole `system_settings` table (mail/backup/security/loglevel, see [Systemeinstellungen](#administration-systemeinstellungen)) — always included, regardless of which libraries were selected. It's only *applied* on import when the caller opts in via `restore_settings` (see below).

### `POST /api/admin/import`

Importiert eine mit `/api/admin/export` erzeugte Datei. Gleiche Konfliktauflösung wie bei Backup-Wiederherstellung (briefing 9.1 + 9.3): pro Bibliotheksname `rename` | `merge` | `overwrite` | `skip`, oder `__all__: "cancel"` zum Abbrechen des gesamten Imports.

**Request**: `multipart/form-data`
| Feld | Typ | Pflicht |
|---|---|---|
| `file` | Datei (JSON-Export) | ✓ |
| `conflict_resolutions` | object, z. B. `{"Romane": "rename", "__all__": "cancel"}` | – |
| `restore_settings` | boolean | – (Default `false`) — wendet `system_settings` aus der Datei auf diese Instanz an |

**Response `200`**
```json
{ "created": ["Krimis"], "merged": [], "overwritten": [], "skipped": ["Romane"], "settings_restored": false }
```

**Fehler `422`**: `{"message": "Invalid export file."}` bei nicht-parsbarem JSON.

> Verhalten bei `merge`: bereits vorhandene EANs in der Zielbibliothek gewinnen (keine Überschreibung, konsistent mit der strikten Duplikat-Regel aus 5.1). Bei `overwrite` werden alle bestehenden Items der Zielbibliothek vorher gelöscht.

## Administration: Backups

Briefing 9.2/9.3. Backups liegen unter `storage/app/private/backups` (siehe `CLAUDE.md`). Automatische Erstellung nach konfiguriertem Intervall läuft über `routes/console.php`, nicht über diese API.

### `GET /api/admin/backups`

**Response `200`**: alle Backups, neueste zuerst (siehe [Datenmodelle](#backup)).

### `POST /api/admin/backups`

Erstellt manuell ein Backup (`trigger: "manual"`) und wendet danach die Retention-Regeln an (siehe `interval_mode`/`retention_count`/`retention_max_age_days` in den Systemeinstellungen).

**Response `201`**: das neue Backup-Objekt.

### `GET /api/admin/backups/{backup}/download`

**Response `200`**: Datei-Download (`.zip`, `Content-Disposition: attachment`).

### `DELETE /api/admin/backups/{backup}`

Löscht Datei und Datenbankeintrag. **Response**: `204 No Content`.

### `POST /api/admin/backups/{backup}/restore`

**Request** (optional): `{ "conflict_resolutions": { "Romane": "overwrite" }, "restore_settings": false }`

**Aktueller Status**: ⚠️ **nicht implementiert.** `BackupService::restore()` wirft immer eine Exception — dies äußert sich als `500`-Fehler mit der Meldung `"Not yet implemented — see method docblock."`. Die Konfliktauflösungs-Logik selbst existiert bereits (geteilt mit `POST /api/admin/import`), ebenso ist `restore_settings` bereits bis zum Service durchgereicht; offen ist weiterhin das Zurücklesen einer Backup-Zip in das Import-Array-Format.

## Administration: Systemeinstellungen

Briefing 15. Laufzeit-Konfiguration (kein Neustart nötig), gespeichert in der `system_settings`-Tabelle — zu unterscheiden von den wenigen echten `MEDINV_*`-Umgebungsvariablen (siehe `CLAUDE.md`, Kapitel 16 des Briefings).

### `GET /api/admin/settings`

**Response `200`**
```json
{
  "mail": {
    "host": null, "port": null, "username": null, "encryption": "starttls",
    "from_address": null, "from_name": null, "healthy": false
  },
  "backup": {
    "interval_mode": "daily", "cron_expression": null,
    "retention_count": null, "retention_max_age_days": null
  },
  "security": {
    "throttle_max_attempts": 6, "throttle_window_minutes": 5, "throttle_lock_minutes": 30
  },
  "loglevel": "INFO"
}
```

### `PUT /api/admin/settings/mail`

**Request**
```json
{
  "host": "smtp.example.com", "port": 587, "username": "user", "password": "secret",
  "encryption": "starttls", "from_address": "noreply@example.com", "from_name": "MedInv"
}
```
| Feld | Typ | Regeln |
|---|---|---|
| `host` | string | Pflicht |
| `port` | integer | Pflicht |
| `username` | string, nullable | – |
| `password` | string, nullable | – |
| `encryption` | `ssl_tls` \| `starttls` \| `none` | Pflicht |
| `from_address` | string, E-Mail | Pflicht |
| `from_name` | string | Pflicht |

`none` disables the opportunistic STARTTLS upgrade entirely (`auto_tls=false` on the SMTP transport, see `AppServiceProvider::boot()`/`MailStatusService::isReachable()`) — for local/internal relays that don't support TLS at all.

**Response `200`**: `{ "healthy": true }` — sofortiger Verbindungstest nach dem Speichern.

### `POST /api/admin/settings/mail/test`

Verschickt eine echte Testmail über die aktuell **gespeicherte** Konfiguration (nicht die evtl. noch ungespeicherten Formularwerte im Frontend) — briefing 12.2. Anders als `healthy` oben (reiner Verbindungstest) prüft dies Zugangsdaten, Absenderadresse und Relay-Regeln tatsächlich Ende-zu-Ende.

**Request**: `{ "to": "someone@example.com" }`

**Response `200`**: `{ "sent": true }`

**Fehler `422`**:
| `error_code` | Bedeutung |
|---|---|
| `not_configured` | Mailserver noch nicht konfiguriert (`mail.host`/`mail.from_address` leer) |
| `mail_test_failed` | Versand fehlgeschlagen; `message` enthält die rohe SMTP-Fehlermeldung |

Beide Fehlerfälle werden zusätzlich mit der Client-IP in `storage/logs/laravel.log` protokolliert (siehe `CLAUDE.md`, `Controller::logApiError()`).

### `PUT /api/admin/settings/backup`

**Request**
```json
{ "interval_mode": "daily", "cron_expression": null, "retention_count": 7, "retention_max_age_days": 7 }
```
| Feld | Typ | Regeln |
|---|---|---|
| `interval_mode` | `daily` \| `weekly` \| `monthly` \| `cron` | Pflicht |
| `cron_expression` | string | Pflicht **nur wenn** `interval_mode=cron` |
| `retention_count` | integer, min. 1, nullable | – |
| `retention_max_age_days` | integer, min. 1, nullable | – |

**Response `200`**: aktualisierter `backup`-Teilbaum (wie in `GET .../settings`).

Standard-Retention je `interval_mode`, falls nicht explizit gesetzt: `daily` → 7 Backups/7 Tage, `weekly` → 4/30, `monthly` → 12/365, `cron` → 10/182.

### `PUT /api/admin/settings/security`

**Request**
```json
{ "throttle_max_attempts": 6, "throttle_window_minutes": 5, "throttle_lock_minutes": 30 }
```
Alle drei Felder Pflicht, integer, min. 1 (briefing 12.4).

**Response `200`**: aktualisierter `security`-Teilbaum.

### `PUT /api/admin/settings/loglevel`

**Request**: `{ "loglevel": "DEBUG" }` — eines von `DEBUG`, `INFO`, `WARNING`, `ERROR`.

**Response `200`**: `{ "loglevel": "DEBUG" }`

### `PUT /api/admin/metadata/plugins/{plugin}`

Aktiviert/deaktiviert ein Metadaten-Plugin oder ändert seine Priorität/Konfiguration (briefing 15.).

**Request** (alle Felder optional)
```json
{ "enabled": false, "priority": 2, "config": { "api_key": "..." } }
```

**Response `200`**: aktualisiertes MetadataPlugin-Objekt.

---

## Datenmodelle (Referenz)

Alle Zeitstempel sind ISO-8601-Strings (`created_at`/`updated_at`, von Eloquent automatisch verwaltet). `id` ist überall eine Auto-Increment-Ganzzahl.

### User

| Feld | Typ | Hinweis |
|---|---|---|
| `id`, `name`, `email` | – | |
| `level` | `guest` \| `user` \| `admin` | briefing 4.2 |
| `is_active` | boolean | Default `true` |
| `is_protected` | boolean | Default `false`; nur beim initialen Admin-Seed-Account `true` |
| `preferred_language` | string, max. 10 | Default `de` |
| `preferred_template` | string, max. 20 | Default `light` |
| `created_at`, `updated_at` | – | |

`password` und `remember_token` sind nie in API-Antworten enthalten (`#[Hidden]`).

### Library

| Feld | Typ | Hinweis |
|---|---|---|
| `id`, `name` | – | |
| `description` | string, nullable | |
| `media_type` | `book` \| `cd` \| `dvd_bluray` | unveränderlich nach dem Anlegen |
| `owner_id` | FK → users | |
| `is_sample_library` | boolean | Default `false` |
| `owner` (Relation) | User (`id`, `name`) | wenn geladen |
| `shares` (Relation) | LibraryShare[] | wenn geladen |

### LibraryShare

| Feld | Typ | Hinweis |
|---|---|---|
| `id`, `library_id` | – | |
| `scope` | `guest` \| `all_users` \| `user` | siehe [Auth](#authentifizierung) |
| `user_id` | FK → users, nullable | nur bei `scope=user` gesetzt |

### Medien-Items (MediaBook / MediaCd / MediaDvdBluray)

Gemeinsame Felder: `id`, `library_id`, `title`, `ean`, `cover_path`, `description`, `release_date` (Datum), `price` (Dezimalzahl, 2 Nachkommastellen).

- **MediaBook** zusätzlich: `authors`, `format`, `genre`, `page_count`, `language`, `publisher`, `isbn10`, `isbn13`.
- **MediaCd** zusätzlich: `artist`, `medium`, `asin`, `disc_count`.
- **MediaDvdBluray** zusätzlich: `medium`, `disc_count`, `runtime_minutes`, `languages`, `cast`, `director`, `production_year`.

### Backup

| Feld | Typ | Hinweis |
|---|---|---|
| `id`, `filename` | – | |
| `size_bytes` | integer | Default `0` |
| `trigger` | `automatic` \| `manual` | |
| `interval_mode` | `daily` \| `weekly` \| `monthly` \| `cron`, nullable | nur bei automatischen Backups gesetzt |
| `status` | `pending` \| `completed` \| `failed` | |

### MetadataPlugin

| Feld | Typ | Hinweis |
|---|---|---|
| `id`, `provider_key` (eindeutig), `name` | – | |
| `media_type` | `book` \| `cd` \| `dvd_bluray` | |
| `enabled` | boolean | Default `true` |
| `config` | object, nullable | providerspezifisch |
| `priority` | integer | Default `0`, niedriger = zuerst |

### SystemSetting

Internes Key-Value-Store-Modell (`key`, `value` als JSON) — wird nie direkt über die API exponiert, sondern immer über die strukturierten `admin/settings/*`-Endpunkte oben.
