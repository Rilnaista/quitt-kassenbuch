# Quitt – Backend

PHP 8.1+ (Slim 4) + SQLite. Einziger Einstieg: `public/api/index.php`, API unter `<base>/api/v1`,
Doku unter `<base>/api/docs` (Redoc, lokal), Vertrag: `.claude/docs/api-contract.md`.

## Lokal starten

```sh
cd backend
php -r "copy('https://getcomposer.org/download/latest-stable/composer.phar', 'tools/composer.phar');"   # einmalig (tools/ ist gitignored)
php tools/composer.phar install
php bin/install.php "Unsere Kasse" 1234          # Erstinstallation, gibt den Wiederherstellungscode aus
php -S localhost:8080 -t public bin/dev-router.php
# -> http://localhost:8080/api/v1/health , http://localhost:8080/api/docs/
```

- Datenordner: `QUITT_DATA_DIR` > `config.local.php` (`return ['data_dir' => '...'];`) > `backend/data`.
- Unterordner testen: `QUITT_DEV_BASE=/kasse php -S ...` -> `http://localhost:8080/kasse/api/v1/health`.
- Tests: `php tools/composer.phar test` (PHPUnit). Mit PHP 8.1 prüfen:
  `docker run --rm -v "$PWD":/app -w /app php:8.1-cli php vendor/bin/phpunit`.
- Apache/Webspace: `public/api/.htaccess` leitet `/api/v1/*` auf `index.php` (inkl. Authorization-Header bei CGI).

## Aufbau

| Ort | Inhalt |
|---|---|
| `modules/<domaene>.php` | Routen + Download-Ziele je Domäne (automatisch geladen, alphabetisch) |
| `src/Controller/` | dünne HTTP-Schicht, eine Klasse je Domäne |
| `src/Service/` | Fachlogik, ohne HTTP testbar |
| `src/Http/` | Registrar, Access-Stufen, Fehlerformat, Middleware (Access, NoStore, Migration) |
| `src/Db/` | PDO/SQLite (`Database`), `Migrator`, `Meta`, `Sequences` |
| `src/App/Bootstrap.php` | Container (Kerndienste) + Slim-App |
| `migrations/NNNN_name.php` | Schema-Änderungen (nur "up"); vor Migration einer bestehenden DB automatische Sicherung |
| `lang/de.php` | alle nutzersichtbaren Texte (`error.<code>`, `validation.*`) |
| `public/api/openapi.json` | OpenAPI 3 (von Hand gepflegt, Test gleicht mit Routen ab) |
| `data/` (gitignored) | DB, Belege, Logs, Sicherungen – gesperrt per `.htaccess` + `index.php` |

Middleware-Reihenfolge: NoStore -> Fehlerformat -> Migration -> Routing -> Access (je Route).

## Neuen Controller / neue Domäne anbinden

1. `src/Controller/XyzController.php` (extends `Controller`; Methoden `(Request, Response, array $args)`).
   Logik in `src/Service/XyzService.php`.
2. Dienst registrieren – im Modul selbst, damit keine zentrale Datei angefasst werden muss:
   ```php
   // modules/xyz.php
   return static function (Registrar $r): void {
       $r->container->set(XyzService::class, fn ($c) => new XyzService($c->get(Database::class) /* ... */));
       $r->get('/xyz', [XyzController::class, 'list'], Access::READ);
       $r->post('/xyz', [XyzController::class, 'create'], Access::WRITE);
       // Download mit ?ticket=: $r->download('/xyz.pdf', [...], Access::READ);
       //                         $r->downloadTarget('xyz', Access::READ, fn (array $p): string => 'xyz.pdf?from=...');
   };
   ```
   Zugriffsstufen (`src/Http/Access.php`): `PUBLIC` (auch vor Installation), `OPEN` (nach Installation, ohne Auth),
   `READ` (Token oder "Lesen ohne PIN"), `WRITE` (Token; sonst 403 `pin_required` bzw. 401), `PRIVATE` (immer Token).
3. In `public/api/openapi.json` muss jede Operation existieren, mit passendem `x-quitt-access` und `x-quitt-ticket`
   (`OpenApiRoutesTest` prüft das). Endpunkte des Vertrags sind dort bereits beschrieben.
4. Fehler: `throw new ApiException(status, 'code')` bzw. `ApiException::validation(['feld' => 'validation.schluessel'])`;
   Texte in `lang/de.php` ergänzen (`error.<code>`), echte Umlaute (Test).
5. Schemaänderung: neue Datei `migrations/0002_xyz.php` – nie Schema von Hand ändern.
6. Je Endpunkt ein Test in `tests/Api/` (Erfolg + wichtigster Fehler), Services direkt in `tests/Unit/`.

## Erweiterungspunkte für folgende Schritte

- `BackupService::autoBackup(reason)` erzeugt das volle Sicherungs-ZIP (manifest.json, database.sqlite, app-settings.json,
  receipts/); Wiederherstellen: `RestoreService` (Temp-Entpacken, Namens-Whitelist, atomarer Austausch, Migrationen).
- `InstallService::install(group_name, pin): recovery_code` für `setup.php`.
- KI-Erkennung: Platzhalter in `modules/recognition.php` + `RecognitionController` (409/501) – komplett ersetzen.
- Download-Ziele `statement`, `backup`, `diagnostics`: per `$r->downloadTarget()` im jeweiligen Modul registrieren.
- `Log\Masker` für das Diagnosepaket wiederverwenden (entfernt PIN, Tokens, Codes, ai_key).

## Release-Paket, Einrichtung, Update (REQ-060/061)

Layout und Regeln: `.claude/docs/release-layout.md`.

- Bauen: `scripts/build-release.sh X.Y.Z[-rcN]` -> `dist-release/quitt-X.Y.Z.zip` + `.sha256` + `setup.php`.
  Frontend wird in einen eigenen Ordner gebaut (`frontend/dist` bleibt unberührt); `QUITT_FRONTEND_DIST=<ordner>`
  verwendet einen fertigen Build. Vorlagen für `api/index.php` und die `.htaccess`-Dateien: `scripts/release/`.
- Im Paket zeigt `api/index.php` auf `../app` und übergibt `../data` als Standard-Datenordner
  (`Bootstrap::run($appDir, $defaultDataDir)`; ENV `QUITT_DATA_DIR` bzw. `app/config.local.php` haben Vorrang).
- `setup/setup.php`: eigenständiger Assistent (PHP-7-Syntax, App-Code erst ab 8.1). Nutzt nach dem Entpacken
  `InstallService`, `SettingsService`, `RecognitionService::test()` und `RestoreService` der App. Sperrt sich über
  `data/.setup-lock` und löscht sich (außer Docker) selbst. Protokoll-Kanal `setup`.
- Update: `UpdateService` (`modules/update.php`), Quelle `update_repo` (Config, Standard `Rilnaista/quitt-kassenbuch`).
  Nur im Release-Layout; ersetzt alle Einträge der obersten Ebene außer `data/`, `setup.php`, übernimmt `app/config.local.php`.
  HTTP-Zugriff über `Quitt\Update\HttpClient` (in Tests `FakeHttpClient`).
- Ende-zu-Ende: `scripts/e2e-setup.sh [--docker]` (Paket bauen, Setup per curl im Unterordner, Wiederherstellung,
  Docker-Modus, In-App-Update inkl. Rollback; mit `--docker` zusätzlich Apache mit echten `.htaccess`-Proben und PHP 7.4).
  `E2E_PACKAGE=<zip>` verwendet ein vorhandenes Paket. `php -S`-Router für Paketordner: `scripts/release/dev-router.php`.
