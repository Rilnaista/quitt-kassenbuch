# Quitt als Docker-Container (z. B. Synology)

Quitt läuft als Container auf Port 8080. Alle deine Daten liegen im Ordner `/data` – den legst du auf deinem NAS an, damit sie bei Updates erhalten bleiben.

## Installation auf der Synology (Container Manager)

1. Im **Datei-Manager** einen Ordner anlegen, z. B. `docker/quitt/` und darin `quitt-data`.
2. **Container Manager** öffnen → **Projekt** → **Erstellen**.
3. Name: `quitt`, Pfad: den Ordner `docker/quitt`. Quelle: **docker-compose.yml erstellen** und den Inhalt der Datei `docker-compose.yml` aus diesem Ordner einfügen.
4. Weiter → **Fertig**. Der Container Manager lädt das Image und startet Quitt.
5. Im Browser `http://<NAS-Adresse>:8080/setup.php` öffnen und dem Assistenten folgen (Systemcheck, Name der Kasse, PIN, Wiederherstellungscode notieren).
6. Danach ist Quitt unter `http://<NAS-Adresse>:8080/` erreichbar. Die Einrichtungsseite sperrt sich selbst.

Ohne Compose: **Registry** → `ghcr.io/rilnaista/quitt-kassenbuch` herunterladen → Container erstellen mit Port `8080` und Ordner `quitt-data` ↔ `/data`.

Port ändern: In der Compose-Datei links ändern (`"9090:8080"`). Wer den Port im Container ändern will, setzt `QUITT_PORT`.

## HTTPS und Zugriff von unterwegs

Für den Einsatz als App auf dem Handy ist HTTPS nötig. Richte in der Synology unter **Systemsteuerung → Anmeldeportal → Erweitert → Reverse Proxy** eine Regel ein:
Quelle `HTTPS`, dein Domainname, Port 443 → Ziel `HTTP`, `localhost`, Port 8080. Das Zertifikat besorgst du unter **Sicherheit → Zertifikat** (Let's Encrypt). Quitt nicht ohne HTTPS ins Internet stellen.

## Update

Neues Image ziehen und neu starten: im Container Manager unter **Projekt** das Projekt **Aktion → Erstellen/Neu erstellen** (oder per Kommandozeile `docker compose pull && docker compose up -d`). Deine Daten im Ordner `quitt-data` bleiben erhalten; Datenbank-Änderungen werden beim Start automatisch mit vorheriger Sicherung eingespielt. Die In-App-Aktualisierung ist im Docker-Betrieb abgeschaltet.

## Sicherung

Kopiere regelmäßig den Ordner `quitt-data` oder nutze in der App die Funktion „Sicherung“.

## Selbst bauen (Entwickler)

```
scripts/build-release.sh 0.1.0
docker build -f docker/Dockerfile --build-arg VERSION=0.1.0 -t quitt .
```
