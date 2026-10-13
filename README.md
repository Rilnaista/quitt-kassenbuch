<p align="center">
  <img src="frontend/public/favicon.svg" alt="Quitt-Logo" width="96" height="96">
</p>

<h1 align="center">Quitt</h1>
<p align="center"><strong>das Kassenbuch für kleine Gemeinschaftskassen</strong></p>
<h2 align="center">Scannen. Buchen. Quitt.</h2>

## Kurz gesagt

Quitt ist ein einfaches Kassenbuch für Gruppen, die gemeinsam Geld verwalten. Du fotografierst den Beleg, Quitt erkennt die Positionen, du bestätigst die Buchung – gequittet. Alles läuft als App auf iPhone, Android und PC, auch ohne Netz, und bleibt auf deinem eigenen Webspace oder NAS.

> **Wichtig: HTTPS.** Offline-Nutzung und die Installation als App funktionieren nur über **HTTPS** (Vorgabe der Browser). Ohne HTTPS läuft Quitt nur online im Browser.

## Für wen ist Quitt?

Für Elternrat, Elternpflegschaft, Klassenkasse und Klassenfahrt, Verein sowie Gruppen- oder WG-Kasse. Quitt ist eine **vertrauensbasierte Kasse** und ausdrücklich **keine Buchhaltungssoftware**. Wer steuerlich saubere Buchführung braucht, ist mit anderen Werkzeugen besser bedient.

## Was Quitt kann

- **Einnahmen und Ausgaben** mit Grund, Kategorie, Bezug und Beleg.
- **Beleg fotografieren, Positionen erkennen lassen:** per KI (Mistral, OpenAI, Anthropic, Gemini, Ollama), **lokal auf dem Gerät** (kostenlos) oder ganz von Hand.
- **Ein Beleg für mehrere Buchungen** und **fortlaufende Belegnummern**.
- **Übersicht** mit Saldo und Ausgaben je Kategorie.
- **Papierkorb**, falls du dich vertippst.
- **Kontoauszug als PDF** im Bankstil, mit Zwischensaldo und Übertrag.
- **App für iPhone, Android und PC**; **offline** nutzbar, mit Abgleich, sobald du wieder Netz hast.
- **PIN-Schutz** (Lesen auf Wunsch auch ohne PIN).
- **Sicherung und Wiederherstellen**, **Update direkt in der App**, **Hilfe und Diagnose**.
- **Barrierearm:** Einnahme und Ausgabe sind immer mit Pfeil und Vorzeichen gekennzeichnet; das Farbschema ist auf Farbsehschwäche abgestimmt.

Schon gequittet? Dann ist die Kasse für heute erledigt.

## Installation

### A) Auf normalem Webspace (einfachster Weg)

**Voraussetzungen:** PHP ab 8.1 mit den Erweiterungen `pdo_sqlite`, `zip`, `json`, `mbstring`, dazu `curl` oder `allow_url_fopen`; Apache mit `.htaccess`.

1. Lade **nur die Datei `setup.php`** aus dem [neuesten Release](https://github.com/Rilnaista/quitt-kassenbuch/releases) auf deinen Webspace hoch.
2. Rufe `setup.php` im Browser auf. Der Assistent prüft alles und richtet Quitt für dich ein.
3. **Schreibe den Wiederherstellungscode auf** und bewahre ihn sicher auf.
4. Öffne Quitt auf dem Handy und wähle **„Zum Home-Bildschirm"**.

### B) Docker / Synology

Quitt gibt es als Image `ghcr.io/rilnaista/quitt-kassenbuch:latest`. Der Container läuft auf **Port 8080**, alle Daten liegen im **Volume `/data`** – leg dafür einen Ordner auf dem NAS an, damit beim Update nichts verloren geht. Auf der Synology legst du im Container Manager ein Projekt mit der mitgelieferten `docker-compose.yml` an und öffnest dann `http://<NAS-Adresse>:8080/setup.php`. Für die App auf dem Handy richtest du per Reverse Proxy HTTPS ein. Schritt für Schritt steht alles in [docker/README.md](docker/README.md).

## PIN vergessen?

Gib den **Wiederherstellungscode** ein, den du bei der Einrichtung notiert hast. Alternativ legst du eine **leere Datei namens `reset-pin`** in den Datenordner.

## Probleme?

Öffne in der App **Einstellungen > Diagnose** und wähle **„Diagnosepaket herunterladen"**. Ansehen kannst du es mit `diagnose.html` (liegt in der Installation; Quelle: [tools-diagnose/](tools-diagnose/)). Fehler meldest du über die [GitHub-Issues](https://github.com/Rilnaista/quitt-kassenbuch/issues). Mehr dazu in [CONTRIBUTING.md](CONTRIBUTING.md).

## Entwicklung

Kurz und knapp: [backend/README.md](backend/README.md), [frontend/README.md](frontend/README.md) und [scripts/build-release.sh](scripts/build-release.sh) (baut das Release).

## Lizenz und Credits

Quitt steht unter der **AGPL-3.0** ([LICENSE](LICENSE)). Verwendete Bibliotheken: [CREDITS-backend.md](CREDITS-backend.md) und [CREDITS-frontend.md](CREDITS-frontend.md).

Quitt ist ein unabhängiges, nicht-kommerzielles Open-Source-Projekt.
