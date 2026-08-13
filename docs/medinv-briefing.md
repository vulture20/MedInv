# MedInv – Konzeptionelles Briefing

**Projektname:** MedInv
**Kategorie:** Medienverwaltung (responsive Web-Anwendung)
**Stand:** Konzept-Entwurf, Version 9

---

## 1. Zielsetzung

MedInv ist eine webbasierte, responsive Anwendung zur zentralen Verwaltung von physischen Mediensammlungen (Bücher, CDs, DVDs/Blu-rays). Mehrere Benutzer sollen gemeinsam auf unterschiedliche, thematisch oder organisatorisch getrennte Bibliotheken zugreifen können – mit klar gestuften Benutzerlevels sowie fein einstellbaren Zugriffs- und Sichtbarkeitsrechten je Bibliothek. Ziel ist ein modular aufgebautes, sicheres System, das sich über Plugins um weitere Metadatenquellen erweitern lässt und sich einfach per Docker betreiben lässt.

Die Erweiterung um zusätzliche Medienarten (z. B. Comics, Brettspiele, Vinyl) ist **kein** Ziel dieses Konzepts – MedInv deckt bewusst und ausschließlich die drei Medienarten **Buch, CD, DVD/Blu-ray** ab.

---

## 2. Zielgruppe

Privatpersonen, Familien, Vereine oder kleine Institutionen (z. B. Bibliotheken im Verein, Sammler-Communities), die eine strukturierte, mehrbenutzerfähige Verwaltung ihrer physischen Medienbestände benötigen – auch unterwegs über das Smartphone.

---

## 3. Kernfunktionen im Überblick

- Benutzerverwaltung mit gestuften Benutzerlevels und feingranularem Rechte-/Sichtbarkeitssystem je Bibliothek
- Mehrere unabhängige „Bibliotheken" (Datenbanken) mit Name und Beschreibung
- Anlage einer Bibliothek mit fest zugeordneter Medienart (Buch / CD / DVD-Blu-ray)
- Erfassung einzelner Medien sowie Massenimport über EAN/ISBN (Hardware-Scanner, Kamera und Textdatei-Import mit EAN-Liste)
- Metadaten-Import aus externen Onlinediensten, plugin-basiert, mit Cover-Auswahl und Auswahl bei mehrdeutigen Treffern
- Abstrahierte Datenbank- und Metadaten-Schicht
- Auswahl des Datenbank-Backends (SQLite, MariaDB, PostgreSQL)
- Export- und Importfunktion (bibliotheksweise oder alle, auch instanzübergreifend) sowie automatisierte, konfigurierbare Backups inkl. Download und Wiederherstellung
- Statistiken über die Bestände
- Visuelle UI-Templates (Start: Hell/Dunkel)
- Mehrsprachige Benutzeroberfläche (Start: Deutsch, Englisch; weitere Sprachpakete durch Administratoren nachrüstbar)
- Umfassende, fuzzy-fähige Suche
- Administrationsbereich inkl. Loglevel-Steuerung und zentraler Systemkonfiguration (Mailserver, Backup, Sicherheit)
- Sicherheitsmechanismen: Passwort-Richtlinie, Passwort-Reset per Mail, Benutzer-Deaktivierung, konfigurierbarer Brute-Force-Schutz, Mailserver-Statusprüfung
- Responsive Bedienoberfläche für Desktop und mobile Endgeräte
- Betrieb im Docker-Container (ohne Datenbank-Backend)

---

## 4. Benutzer- und Rechtemanagement

### 4.1 Benutzerkonten
- Neue Benutzerkonten werden **ausschließlich durch einen Administrator** angelegt (kein Self-Signup).
- Beim ersten Start der Anwendung wird automatisch ein initialer Administrator-Account angelegt, dessen Zugangsdaten über die Umgebungsvariablen `MEDINV_ADMINUSER` und `MEDINV_ADMINPASS` übergeben werden.
- Jeder Benutzer verfügt über benutzerdefinierte Einstellungen (u. a. bevorzugtes Template, bevorzugte Sprache) und kann sich über ein Benutzermenü ausloggen.
- Benutzerkonten lassen sich durch einen Administrator **deaktivieren**, sodass ein Login nicht mehr möglich ist, die Historie/Zuordnung des Kontos aber erhalten bleibt. Eine Reaktivierung ist ebenso möglich.
- Benutzerkonten lassen sich durch einen Administrator zusätzlich **endgültig löschen** sowie **anlegen und bearbeiten** (Name, E-Mail, Benutzerlevel). Ausgenommen ist der **initiale Administrator-Account** (angelegt aus `MEDINV_ADMINUSER`/`MEDINV_ADMINPASS`, siehe oben) — dieser lässt sich weder bearbeiten noch deaktivieren noch löschen, damit eine Installation nie ohne funktionsfähiges Administrator-Konto dasteht.

### 4.2 Benutzerlevel (globale Rollen)
Jeder Benutzer besitzt genau ein globales Benutzerlevel, das die grundsätzlichen Möglichkeiten im System bestimmt:

| Level | Rechte |
|---|---|
| **Gast** | Kann ausschließlich Bibliotheken lesen, die explizit für Gäste freigegeben wurden. Keine Anlage, keine Bearbeitung. |
| **Benutzer** | Kann eigene Bibliotheken anlegen und verwalten sowie alle Bibliotheken lesen, die für ihn freigegeben wurden. |
| **Administrator** | Umfassende Rechte: kann alle Bibliotheken lesen, bearbeiten und löschen, verwaltet Benutzer und Systemeinstellungen. |

### 4.3 Freigabe und Sichtbarkeit je Bibliothek
Zusätzlich zum globalen Benutzerlevel wird **je Bibliothek** festgelegt, wer sie sehen und nutzen darf:
- Eine Bibliothek kann für **Gäste**, für **einzelne oder alle Benutzer** freigegeben werden – jeweils mit Lesezugriff.
- Der Ersteller einer Bibliothek (bzw. ein Administrator) verwaltet diese Freigaben.
- Administratoren sehen und verwalten unabhängig von expliziten Freigaben grundsätzlich alle Bibliotheken.
- Nicht freigegebene Bibliotheken sind für Gäste/Benutzer weder sichtbar noch auffindbar (auch nicht über die Suche).

---

## 5. Bibliotheken (Datenbanken)

- Eine „Bibliothek" ist eine eigenständige Sammlung mit **Name**, **Beschreibung** und einer bei der Anlage fest gewählten **Medienart** (Buch, CD oder DVD/Blu-ray). Die Medienart ist nachträglich nicht änderbar.
- Es können beliebig viele Bibliotheken parallel existieren, auch mehrere derselben Medienart (z. B. „Bücher – Roman-Sammlung" und „Bücher – Fachliteratur").
- Alle von MedInv angelegten Datenbanken/Tabellen erhalten einheitlich das Präfix `MEDINV_`, um Kollisionen mit anderen Anwendungen auf demselben Datenbank-Server zu vermeiden.
- Bei der Ersteinrichtung können optional **Beispiel-Bibliotheken mit Testdaten** angelegt werden, um die Anwendung direkt ausprobieren zu können — je eine pro Medienart (Buch, CD, DVD/Blu-ray) mit jeweils mindestens 10 Einträgen.
- Bibliotheken lassen sich durch ihren Ersteller oder einen Administrator **löschen**; da dies nicht rückgängig gemacht werden kann, fragt die Oberfläche vorher über eine **Sicherheitsabfrage** nach, ob das Löschen wirklich beabsichtigt ist.
- Über die Bibliotheksliste lässt sich jede sichtbare Bibliothek **öffnen**, um ihren Inhalt (die enthaltenen Medien) einzusehen — nicht nur anlegen und löschen sind möglich.

### 5.1 Umgang mit Duplikaten
- Dasselbe Medium (identische EAN) darf **in unterschiedlichen Bibliotheken** jeweils vorhanden sein (z. B. wenn zwei getrennte Sammlungen dasselbe Buch enthalten).
- **Innerhalb derselben Bibliothek** ist ein Medium mit identischer EAN nur **einmal** zulässig. Wird beim manuellen Anlegen oder beim Massenimport eine bereits vorhandene EAN in der Ziel-Bibliothek erkannt, wird der Datensatz **strikt abgelehnt** (kein Anlegen, keine automatische Bestandserhöhung); der Benutzer erhält einen entsprechenden Hinweis.

---

## 6. Medienarten und Attribute

### 6.1 Buch
Titel, Cover, Beschreibung, Autor(en), Format, Genre, Seitenzahl, Sprache, Herausgeber, Erscheinungstermin, Preis, ISBN-10, ISBN-13, EAN

### 6.2 CD
Titel, Cover, Beschreibung, Künstler, Medium, ASIN (optional), Anzahl der Discs, Erscheinungstermin, Preis, EAN

### 6.3 DVD/Blu-ray
Titel, Cover, Beschreibung, Medium, Anzahl der Medien, Spieldauer, Sprache(n), Besetzung, Regisseur, Erscheinungstermin, Produktionsjahr, Preis, EAN

Das Attributset ist je Medienart fest definiert; ein generisches „Zusatzfeld"-Konzept ist im aktuellen Konzept nicht vorgesehen.

---

## 7. Erfassung und Massenimport

### 7.1 Einzelerfassung
Manuelle Anlage eines Mediums mit anschließendem Metadaten-Import (siehe Kap. 8).

### 7.2 Massenimport über EAN/ISBN
Der Massenimport unterstützt **drei** Eingabewege gleichwertig:
- **Hardware-Barcodescanner**, der Codes wie eine Tastatureingabe an die Anwendung übergibt (schnelles Scannen mehrerer Medien am Stück, z. B. beim Einpflegen eines ganzen Regals).
- **Kamera-basiertes Scannen** über Browser/App (Nutzung der Gerätekamera zur Erkennung von Barcodes ohne zusätzliche Hardware).
- **Textdatei-Import:** Hochladen einer Textdatei mit einer Liste zuvor gescannter/erfasster EAN (eine EAN je Zeile). Vor der Verarbeitung fragt die Anwendung nach der **Ziel-Bibliothek**, in die alle in der Datei enthaltenen EAN importiert werden sollen.

Für jeden erkannten Code – unabhängig vom Eingabeweg – wird automatisch der Metadaten-Import angestoßen und – wie in Kapitel 5.1 beschrieben – auf Duplikate innerhalb der Ziel-Bibliothek geprüft (strikte Ablehnung bei Duplikat).

---

## 8. Metadaten-Import

### 8.1 Plugin-Prinzip
Der Metadaten-Import erfolgt über ein **Plugin-System**, sodass zusätzliche Quellen ohne Änderung des Kernsystems nachgerüstet werden können. Jedes Plugin ist einer oder mehreren Medienarten zugeordnet.

### 8.2 Vorgesehene Quellen je Medienart
- **Buch:** Open Library, Hardcover, Amazon, Google Books
- **CD:** MusicBrainz, Amazon, Discogs
- **DVD/Blu-ray:** Amazon, UPCMDB, Emunation.ch

### 8.3 Ablauf
1. Automatischer oder manueller Abgleich über EAN/ISBN bzw. Suchbegriffe.
2. Abfrage der konfigurierten/aktiven Quellen für die jeweilige Medienart.
3. Zusammenführung der gefundenen Treffer zu einer Auswahlübersicht.
4. **Bei mehreren, nicht eindeutigen Treffern** werden dem Benutzer **alle gefundenen Alternativen** nebeneinander präsentiert. Der Benutzer wählt den passenden Datensatz aus oder **lehnt alle Treffer ab** (z. B. um das Medium manuell ohne Metadaten-Übernahme anzulegen oder den Import dieses Mediums zu überspringen).
5. Der Benutzer wählt zusätzlich **ein Cover** aus den gefundenen Bildern des gewählten Treffers aus.
6. Übernahme in das Medien-Datensatzformular, das vor dem Speichern noch bearbeitet werden kann.

---

## 9. Export, Import und Backup

### 9.1 Export / Import
- Beim Export wird ausgewählt, **welche Bibliothek(en)** exportiert werden sollen – einzelne Bibliotheken, eine Mehrfachauswahl oder **„alle"**.
- Exportierte Daten sind so aufgebaut, dass sie sich auch **in eine andere MedInv-Instanz importieren** lassen (z. B. zur Migration auf einen neuen Server oder zur Zusammenführung von Beständen).
- Der Import bietet dieselbe Auswahlmöglichkeit (einzelne Bibliotheken, mehrere oder alle aus einer Exportdatei).
- Existiert am Ziel bereits eine Bibliothek mit identischem Namen, greift dieselbe Konflikt-Abfrage wie bei der Backup-Wiederherstellung (siehe Kap. 9.3).
- Eine Export-/Backup-Datei enthält zusätzlich zu den Bibliotheken auch die **Systemeinstellungen** (Mailserver, Backup-Zeitplan, Sicherheits-Schwellenwerte, Loglevel, siehe Kap. 15). Beim Import ist deren Wiederherstellung **optional**: eine gesonderte Option ("Systemeinstellungen mit wiederherstellen") entscheidet, ob sie auf die Zielinstanz übernommen werden, oder ob nur die Bibliotheksdaten importiert werden und die Systemeinstellungen der Zielinstanz unangetastet bleiben.

### 9.2 Automatische Backups
- Backups werden automatisch in einem **einstellbaren Intervall** erstellt. Für die Intervall-Konfiguration gibt es zwei Modi:
  - **Einfacher Modus:** Auswahlbox mit den Optionen **täglich**, **wöchentlich**, **monatlich**.
  - **Experten-Modus:** freies Feld im **Cron-Format** für individuelle Zeitpläne.
- Für die Aufbewahrung gelten intervallabhängige **Standardwerte**, die jeweils in den Systemeinstellungen überschrieben werden können:

  | Intervall-Modus | Standard-Anzahl Backups | Standard-Maximalalter |
  |---|---|---|
  | Täglich | 7 | 7 Tage |
  | Wöchentlich | 4 | 1 Monat |
  | Monatlich | 12 | 1 Jahr |
  | Cron-Format (Experten) | 10 | 6 Monate |

- Backups, die das eingestellte Alter überschreiten oder über die eingestellte Anzahl hinausgehen, werden **automatisch gelöscht**.

### 9.3 Download und Wiederherstellung von Backups
- Vorhandene Backups lassen sich im Administrationsbereich als Datei **herunterladen** (z. B. für externe Archivierung) oder manuell **löschen**, zusätzlich zur automatischen Löschung nach den Aufbewahrungsregeln aus Kap. 9.2.
- Da ein Backup auch die Systemeinstellungen enthält (Kap. 9.1), gilt bei der Wiederherstellung dieselbe Optionalität: die Systemeinstellungen werden nur bei ausdrücklicher Auswahl mit wiederhergestellt.
- Die **Wiederherstellung** eines Backups ist auf zwei Wegen möglich:
  - über die **Benutzeroberfläche** (Administrationsbereich), oder
  - beim **Containerstart** über die Umgebungsvariable `MEDINV_RESTOREBACKUP` (Angabe des wiederherzustellenden Backups, z. B. für automatisierte Deployments).
- **Konfliktbehandlung:** Ist eine im Backup enthaltene Bibliothek am Zielsystem bereits vorhanden, fragt die Anwendung je betroffener Bibliothek nach dem weiteren Vorgehen:
  - **Umbenennen** – die wiederhergestellte Bibliothek wird unter neuem Namen angelegt, die bestehende bleibt unangetastet.
  - **Zusammenführen** – Datensätze aus dem Backup werden in die bestehende Bibliothek übernommen; bereits vorhandene Datensätze (gleiche EAN, siehe Kap. 5.1) werden dabei übersprungen, neue ergänzt.
  - **Bestehende Version löschen und überschreiben** – die vorhandene Bibliothek wird durch den Stand aus dem Backup ersetzt.
  - **Überspringen** – diese Bibliothek wird aus dem Wiederherstellungsvorgang ausgenommen, übrige Bibliotheken werden regulär verarbeitet.
  - **Abbrechen** – der gesamte Wiederherstellungsvorgang wird nicht durchgeführt.

---

## 10. Architekturprinzipien (konzeptionell)

- **Abstraktion der Datenbankschicht:** Die Anwendungslogik greift nicht direkt auf ein bestimmtes Datenbanksystem zu, sondern über eine einheitliche Schnittstelle – dadurch ist die Wahl zwischen SQLite, MariaDB und PostgreSQL bei der Einrichtung frei möglich, ohne den Rest der Anwendung zu beeinflussen.
- **Abstraktion der Metadatenschicht:** Alle Metadatenquellen sprechen über eine gemeinsame Plugin-Schnittstelle mit der Anwendung, unabhängig von den Eigenheiten des jeweiligen externen Dienstes.
- **Maximale Modularität:** Funktionsbereiche (Benutzerverwaltung, Rechteverwaltung, Bibliotheksverwaltung, Erfassung, Metadaten-Plugins, Statistik, Suche, UI-Templates, Sprachen, Backup/Export) sollen als möglichst unabhängige Bausteine konzipiert sein.
- **Template-System für die Oberfläche:** Auslieferung mit zwei visuellen Templates („Hell" und „Dunkel"), weitere Templates sollen sich nachrüsten lassen.
- **Mehrsprachigkeit:** Sprachtexte der Oberfläche werden als austauschbare/erweiterbare Sprachpakete gepflegt (siehe Kap. 11.4), sodass sich zusätzliche Sprachen nach Deutsch/Englisch nachrüsten lassen, ohne den Kern der Anwendung zu ändern.
- **Responsive Design:** Die Oberfläche passt sich Desktop-, Tablet- und Smartphone-Ansichten an, sodass die Anwendung ohne separate native App mobil nutzbar ist.
- **Containerisierung:** Die Anwendung selbst läuft in einem Docker-Container; das gewählte Datenbank-Backend wird separat betrieben und nicht Teil des Anwendungscontainers.

---

## 11. Benutzeroberfläche

### 11.1 Login
Beim Aufruf der Anwendung erscheint zunächst ein Login-Bildschirm; ohne gültige Anmeldung ist keine Funktion nutzbar. Ist der Mailserver nicht erreichbar oder fehlerhaft konfiguriert, erscheint hier für Administratoren zusätzlich ein Warnhinweis (siehe Kap. 12.2).

### 11.2 Grundlayout nach dem Login

**Seitenleiste links:**
- Startseite
- Erfassung
- Bibliotheken
- Statistiken
- Administration

**Kopfleiste oben, links:**
- Logo
- Anwendungsname
- Suche

**Kopfleiste oben, rechts:**
- Statistiken
- Erfassung
- Administration
- Benutzername mit Benutzermenü (Benutzerdefinierte Einstellungen, Logout)

*Hinweis:* Erfassung, Statistiken und Administration erscheinen sowohl in der linken Seitenleiste als auch als Schnellzugriff in der Kopfleiste rechts – dies ist als bewusster Komfort-Zugriff auf häufig genutzte Bereiche zu verstehen. In der mobilen Ansicht wird die Seitenleiste voraussichtlich in ein ausklappbares Menü überführt.

Die **Startseite** zeigt einen Auszug aus allen für den Benutzer sichtbaren Bibliotheken (Name, Medienart, Besitzer), mit einem Verweis auf die vollständige Liste unter „Bibliotheken", sobald mehr Bibliotheken sichtbar sind, als der Auszug anzeigt.

### 11.3 Sichtbarkeit von Menüpunkten
Menüpunkte bzw. deren Inhalte orientieren sich am Benutzerlevel (Gast/Benutzer/Administrator) sowie an den Freigaben der jeweiligen Bibliotheken (z. B. „Erfassung" nur dort nutzbar, wo Schreibrechte bestehen; „Administration" nur für Administratoren).

### 11.4 Sprache
- Die Oberfläche steht initial in **Deutsch** und **Englisch** zur Verfügung. Jeder Benutzer kann seine bevorzugte Sprache in den benutzerdefinierten Einstellungen wählen.
- Weitere Sprachpakete können **ausschließlich von Administratoren** eingepflegt bzw. gepflegt werden.
- Als Format für Sprachpakete wird eine **einfache, dateibasierte Schlüssel-Wert-Struktur** vorgeschlagen, wie sie durch verbreitete Internationalisierungs-Bibliotheken im Web-Umfeld (z. B. i18next-kompatibles JSON) etabliert ist: eine Datei je Sprache, mit sprechenden Textschlüsseln je Wert. Das ist ohne Spezialwerkzeug mit einem einfachen Texteditor bearbeitbar und deckt sich mit einem breit genutzten Industriestandard. *(Feinschliff des genauen Dateiformats ist Teil der technischen Umsetzung.)*

---

## 12. Sicherheit

### 12.1 Passwort-Richtlinie
Passwörter müssen mindestens **10 Zeichen** lang sein und mindestens folgende Zeichenklassen enthalten:
- einen Großbuchstaben
- einen Kleinbuchstaben
- eine Ziffer
- ein Sonderzeichen

### 12.2 Mailserver-Konfiguration und Statusprüfung
Für den Mailversand (u. a. Passwort-Reset) wird der Mailserver zentral über die **Systemkonfiguration** hinterlegt:
- SMTP-Host
- SMTP-Port
- SMTP-Benutzername (optional)
- SMTP-Passwort (optional)
- Verschlüsselung: SSL/TLS, STARTTLS oder **keine Verschlüsselung** (für interne/lokale Relays ohne TLS-Unterstützung)
- Absenderadresse und Absendername

Ist der Mailserver **nicht erreichbar oder nicht bzw. fehlerhaft konfiguriert**, wird dies **Administratoren** beim Einloggen durch eine **rote Warnmeldung** angezeigt. Solange dieser Zustand besteht, ist die **Passwort-Reset-Funktion deaktiviert** (in der Oberfläche ausgegraut) und für Benutzer nicht nutzbar.

Zusätzlich zur reinen Erreichbarkeitsprüfung (TCP-Verbindungsaufbau) lässt sich im Administrationsbereich eine **Testmail** an eine frei wählbare Adresse verschicken, um die konfigurierte Mailausgabe (Zugangsdaten, Absenderadresse, Relay-Regeln) tatsächlich Ende-zu-Ende zu prüfen statt nur die Verbindung.

### 12.3 Passwort-Reset
Benutzer können ihr Passwort selbstständig über einen **E-Mail-basierten Reset-Prozess** zurücksetzen (Anforderung eines Reset-Links per Mail, Vergabe eines neuen, richtlinienkonformen Passworts). Voraussetzung sind eine hinterlegte, gültige E-Mail-Adresse je Benutzerkonto sowie eine funktionsfähige Mailserver-Anbindung (siehe Kap. 12.2).

### 12.4 Schutz vor Brute-Force-Angriffen
- Bei wiederholten fehlerhaften Login-Versuchen für **denselben Benutzer** greift ein **Throttling**: Standardmäßig führen **6 Fehlversuche innerhalb von 5 Minuten** zu einer **Sperre von 30 Minuten** für dieses Benutzerkonto.
- Beide Werte (Anzahl Fehlversuche/Zeitfenster sowie Sperrdauer) sind in den **Systemeinstellungen konfigurierbar**.
- Zugriffe aus einem als vertrauenswürdig definierten IP-Bereich sind von diesem Schutzmechanismus **ausgenommen**. Dieser Bereich wird über die Umgebungsvariable `MEDINV_TRUSTEDIP` konfiguriert.

### 12.5 Benutzer-Deaktivierung
Siehe Kap. 4.1 – Administratoren können Benutzerkonten deaktivieren, ohne sie zu löschen.

---

## 13. Suche

- Die Suche bezieht **alle Attribute aller Medienarten** ein, auf die der Benutzer laut Rechtekonzept Zugriff hat.
- Eine **Fuzzy-Suche** (tolerant gegenüber Tippfehlern/Schreibvarianten) ist per Checkbox zuschaltbar bzw. abschaltbar.
- Suchergebnisse sollen erkennen lassen, aus welcher Bibliothek ein Treffer stammt.

---

## 14. Statistiken

Aus den Bibliotheksdaten sollen diverse Auswertungen generierbar sein, u. a. denkbar:
- Bestandsgröße je Bibliothek / Medienart
- Verteilung nach Genre, Sprache, Erscheinungsjahr, Herausgeber/Künstler/Regisseur
- Gesamtwert des Bestands (Preisfeld)
- Zeitlicher Zuwachs des Bestands

*(Genauer Statistikumfang ist im weiteren Verlauf noch zu konkretisieren.)*

---

## 15. Administration und Systemkonfiguration

- Verwaltung von Benutzern (anlegen, bearbeiten, Benutzerlevel zuweisen, deaktivieren/reaktivieren, löschen — außer dem initialen Administrator-Account, siehe Kap. 4.1)
- Verwaltung der Bibliotheken (Anlage, Beschreibung, Zuordnung Medienart, Freigaben für Gäste/Benutzer, Löschen mit Sicherheitsabfrage, siehe Kap. 5)
- Verwaltung/Aktivierung der Metadaten-Plugins
- Verwaltung der UI-Templates und Sprachpakete (Sprachpakete nur durch Administratoren)
- Ausführung von Export/Import (Auswahl einzelner, mehrerer oder aller Bibliotheken) inklusive Konfliktbehandlung
- Konfiguration von Backup-Intervall (Auswahlbox oder Cron-Format) und Aufbewahrungsregeln (Anzahl/Alter)
- Download, manuelles Löschen und Wiederherstellung von Backups (inkl. Konfliktbehandlung je Bibliothek)
- Konfiguration des Mailservers (SMTP) für den Mailversand inkl. Statusanzeige bei Fehlkonfiguration
- Konfiguration der Brute-Force-Schutzschwellen (Fehlversuche, Zeitfenster, Sperrdauer)
- **Loglevel-Einstellung:** einstellbar sowohl im Administrationsbereich als auch über die Umgebungsvariable `MEDINV_LOGLEVEL`, mit den Stufen DEBUG, INFO, WARNING, ERROR
- **Log-Inhalt:** protokollierte Fehler (u. a. fehlgeschlagene Logins, verweigerte Aktionen gegen das geschützte Administrator-Konto, fehlgeschlagene Testmails) enthalten neben Fehler-Code und -Meldung auch die **IP-Adresse des anfragenden Clients**, damit sich ein gemeldetes Problem im Log nachvollziehen lässt.

---

## 16. Relevante Umgebungsvariablen

| Variable | Zweck |
|---|---|
| `MEDINV_ADMINUSER` | Benutzername des initialen Administrator-Kontos |
| `MEDINV_ADMINPASS` | Passwort des initialen Administrator-Kontos |
| `MEDINV_LOGLEVEL` | Initialer Loglevel (DEBUG / INFO / WARNING / ERROR) |
| `MEDINV_TRUSTEDIP` | IP-Bereich, der vom Brute-Force-Throttling beim Login ausgenommen ist |
| `MEDINV_RESTOREBACKUP` | Angabe eines Backups, das beim Containerstart automatisiert wiederhergestellt werden soll |
| `MEDINV_SEED_SAMPLE_LIBRARY` | Legt bei der Ersteinrichtung optional die Beispiel-Bibliotheken mit Testdaten an, eine je Medienart (Kap. 5) |
| `MEDINV_PortWeb` | Port, auf dem die Anwendung im Docker-Deployment erreichbar ist — bedient sowohl die Oberfläche als auch, unter `/api`, die API; ein separater API-Port ist bewusst nicht vorgesehen, siehe `CLAUDE.md` |
| `MEDINV_URL` | Öffentliche URL, unter der die Instanz erreichbar ist (z. B. hinter einem Reverse Proxy auf einer echten Domain) — wird zusätzlich zu `localhost`/`127.0.0.1` automatisch als gültiger Login-Ursprung akzeptiert |
| `MEDINV_DB_CONNECTION`, `MEDINV_DB_HOST`, `MEDINV_DB_PORT`, `MEDINV_DB_DATABASE`, `MEDINV_DB_USERNAME`, `MEDINV_DB_PASSWORD`, `MEDINV_DB_PREFIX` | Anbindung der in Kap. 10 geforderten Datenbankabstraktion (SQLite/MariaDB/PostgreSQL) — bewusst mit `MEDINV_`-Präfix statt der sonst üblichen `DB_*`-Namen, damit jede von der Anwendung gelesene Umgebungsvariable einheitlich mit `MEDINV_` beginnt |

Alle übrigen Einstellungen (Mailserver, Backup-Intervalle/-Aufbewahrung, Throttling-Schwellen, Sprachen, Templates) werden über die Systemkonfiguration in der Anwendung verwaltet, nicht über Umgebungsvariablen.

---

## 17. Ausdrücklich festgelegte Annahmen aus der Abstimmung

- Barcode-Scan im Massenimport unterstützt **sowohl** Hardware-Scanner **als auch** kamera-basiertes Scannen.
- Neue Benutzerkonten werden **ausschließlich von Administratoren** angelegt, kein Self-Signup.
- Das Konzept ist bewusst **auf die drei Medienarten Buch, CD, DVD/Blu-ray begrenzt**.
- Duplikate (gleiche EAN) sind **bibliotheksübergreifend erlaubt**, **innerhalb derselben Bibliothek jedoch strikt ausgeschlossen** (keine automatische Bestandserhöhung).
- Bei mehrdeutigen Metadaten-Treffern erhält der Benutzer **alle Alternativen zur Auswahl** und kann den Import auch **vollständig ablehnen**.
- Export/Import erfolgt **bibliotheksweise auswählbar (einzeln, mehrfach oder alle)** und ist **instanzübergreifend** nutzbar.
- Backup-Standardwerte je Intervall-Modus wie in Kap. 9.2 tabellarisch festgehalten – **bestätigt:** 1 Jahr Maximalalter gehört zum Modus „monatlich" (nicht „jährlich").
- Backups sind über die Oberfläche **herunterladbar** sowie über die **Oberfläche oder `MEDINV_RESTOREBACKUP`** wiederherstellbar; bei Namenskonflikten entscheidet der Benutzer je Bibliothek zwischen Umbenennen, Zusammenführen, Überschreiben, Überspringen oder Abbrechen (siehe Kap. 9.3). Dieselbe Logik gilt beim instanzübergreifenden Import (Kap. 9.1).
- Throttling-Standardwerte: **6 Fehlversuche in 5 Minuten → 30 Minuten Sperre**, konfigurierbar in den Systemeinstellungen.
- Die Oberfläche startet **zweisprachig (Deutsch/Englisch)**; weitere Sprachpakete dürfen **nur Administratoren** nachrüsten, im Format einfacher, weit verbreiteter Sprachdateien (z. B. i18next-kompatibles JSON).
- Bei nicht erreichbarem/fehlkonfiguriertem Mailserver wird Administratoren beim Login eine **rote Warnmeldung** angezeigt; die **Passwort-Reset-Funktion ist währenddessen deaktiviert/ausgegraut**.

---

## 18. Offene Punkte für die weitere Konkretisierung

- Genauer Umfang und Darstellungsform der Statistiken
- Exaktes Dateiformat und Ablagestruktur der Sprachpakete (Feinschliff im technischen Konzept)
- Genaues Dateiformat/Struktur der Backup- und Exportdateien (relevant für Fremdinstanz-Kompatibilität)

---

## 19. Vorschlag für eine Verzeichnisstruktur

Der folgende Verzeichnisbaum ist ein **konzeptioneller Vorschlag**, wie sich die beschriebene Modularität (Kap. 10) organisatorisch abbilden ließe. Er dient als grobe Orientierung für die spätere technische Umsetzung, nicht als verbindliche Implementierungsvorgabe.

```
medinv/
├── docker/
│   ├── Dockerfile
│   ├── docker-compose.yml
│   └── entrypoint.sh                  # liest MEDINV_*-Umgebungsvariablen beim Start
│
├── app/
│   ├── core/                          # Benutzer, Login, Sitzungen
│   │   ├── users/
│   │   ├── auth/
│   │   └── permissions/               # Benutzerlevel, Freigaben je Bibliothek
│   │
│   ├── security/                      # Passwort-Richtlinie, Throttling, TRUSTEDIP
│   │
│   ├── libraries/                     # Verwaltung der Bibliotheken selbst
│   │   ├── models/
│   │   └── services/
│   │
│   ├── media/                         # Medienarten-Definitionen (Attribute)
│   │   ├── book/
│   │   ├── cd/
│   │   └── dvd_bluray/
│   │
│   ├── capture/                       # Erfassung / Massenimport
│   │   ├── scanner_input/             # Hardware-Scanner
│   │   ├── camera_scan/               # Kamera-basiertes Scannen
│   │   └── textfile_import/           # EAN-Textdatei-Import
│   │
│   ├── metadata/
│   │   ├── plugin_interface/          # gemeinsame Plugin-Schnittstelle
│   │   └── plugins/
│   │       ├── book/
│   │       │   ├── open_library/
│   │       │   ├── hardcover/
│   │       │   ├── amazon/
│   │       │   └── google_books/
│   │       ├── cd/
│   │       │   ├── musicbrainz/
│   │       │   ├── amazon/
│   │       │   └── discogs/
│   │       └── dvd_bluray/
│   │           ├── amazon/
│   │           ├── upcmdb/
│   │           └── emunation/
│   │
│   ├── database/                      # Datenbank-Abstraktionsschicht
│   │   ├── interface/
│   │   ├── sqlite/
│   │   ├── mariadb/
│   │   └── postgresql/
│   │
│   ├── export_import/                 # Export/Import inkl. Konfliktbehandlung
│   ├── backup/                        # Erstellung, Rotation, Wiederherstellung
│   ├── search/                        # Volltext- und Fuzzy-Suche
│   ├── statistics/
│   ├── mail/                          # SMTP-Anbindung, Statusprüfung
│   └── admin/                         # Systemkonfiguration, Loglevel
│
├── ui/
│   ├── templates/                     # visuelle Templates
│   │   ├── light/
│   │   └── dark/
│   ├── languages/                     # Sprachpakete
│   │   ├── de.json
│   │   └── en.json
│   └── components/                    # Seitenleiste, Kopfleiste, Formulare, Login
│
├── config/
│   └── default-settings...            # Vorbelegte Systemeinstellungen
│
├── sample-data/                       # Beispieldaten für optionale Test-Bibliothek
│
├── backups/                           # Ablage der automatischen Backups (Volume)
├── logs/                              # Log-Ausgabe gemäß MEDINV_LOGLEVEL (Volume)
│
└── docs/
    └── medinv-briefing.md             # dieses Konzeptdokument
```

**Leitgedanken hinter der Struktur:**
- `app/media/`, `app/metadata/plugins/`, `ui/templates/` und `ui/languages/` sind bewusst als **erweiterbare Verzeichnisse** angelegt – neue Metadaten-Plugins, Templates oder Sprachpakete lassen sich als zusätzlicher Unterordner ergänzen, ohne bestehenden Code/Konfiguration zu verändern.
- `app/database/` kapselt die drei Backend-Optionen hinter einer gemeinsamen Schnittstelle (`interface/`), passend zur geforderten Abstraktion.
- `backups/`, `logs/` und ggf. das SQLite-Datenverzeichnis liegen außerhalb des Anwendungscodes, damit sie als Docker-Volumes persistiert werden können, während der Anwendungscontainer selbst zustandslos bleibt.
- `docker/entrypoint.sh` ist der zentrale Ort, an dem alle `MEDINV_*`-Umgebungsvariablen (Admin-User, Loglevel, Trusted-IP, Restore-Backup) beim Start ausgewertet werden.

---

## 20. Gewählter technischer Stack (Umsetzungsentscheidung)

Auf Basis dieses Konzepts wurde für die technische Umsetzung folgender Stack festgelegt:

- **Backend:** PHP / Laravel — liefert Routing, Validierung, Queues (für Backup-Jobs) und über Eloquent eine native Mehr-Dialekt-Datenbankanbindung (SQLite, MariaDB, PostgreSQL) mit, passend zur in Kap. 10 geforderten Datenbankabstraktion.
- **Frontend:** React + TypeScript als eigenständige SPA, die über eine REST-API (Laravel Sanctum für die Authentifizierung) mit dem Backend kommuniziert.
- **Datenbankschicht:** Eloquent ORM auf Basis von Laravels eingebauter Mehr-Dialekt-Unterstützung (SQLite/MariaDB/PostgreSQL), gekapselt hinter Repository-Interfaces je Domäne, damit die Wahl des Backends bei der Einrichtung frei bleibt, ohne Anwendungscode zu beeinflussen.

Die in Kap. 19 vorgeschlagene, medienart- und plugin-orientierte Modulstruktur wird auf dieses Stack wie folgt abgebildet: die Laravel-Anwendung liegt unter `backend/`, organisiert in fachliche Module statt der Standard-`app/`-Einteilung; die React-SPA liegt unter `frontend/`. Details siehe `CLAUDE.md`.
