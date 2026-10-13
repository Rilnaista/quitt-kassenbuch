# Credits – Backend (Quitt)

Quitt selbst steht unter **AGPL-3.0-or-later**. Das Backend nutzt ausschließlich freie Bibliotheken.
Quelle der Angaben: `composer licenses` (Stand der `backend/composer.lock`).
Bei Änderungen an den Abhängigkeiten diese Liste aktualisieren.

## Laufzeit (im Release-Paket enthalten, `backend/vendor/`)

| Paket | Version | Lizenz | Zweck |
|---|---|---|---|
| slim/slim | 4.15.3 | MIT | Router / HTTP-Kern |
| slim/psr7 | 1.8.0 | MIT | PSR-7-Implementierung |
| nikic/fast-route | 1.3.1 | BSD-3-Clause | Routing (Abhängigkeit von Slim) |
| psr/log | 3.0.2 | MIT | Logger-Schnittstelle (PSR-3) |
| psr/container | 2.0.2 | MIT | Container-Schnittstelle (PSR-11) |
| psr/http-message | 2.0 | MIT | PSR-7-Schnittstellen |
| psr/http-factory | 1.1.0 | MIT | PSR-17-Schnittstellen |
| psr/http-server-handler | 1.0.2 | MIT | PSR-15-Schnittstellen |
| psr/http-server-middleware | 1.0.2 | MIT | PSR-15-Schnittstellen |
| fig/http-message-util | 1.1.5 | MIT | HTTP-Konstanten (Abhängigkeit von Slim) |
| ralouphie/getallheaders | 3.0.3 | MIT | Polyfill (Abhängigkeit von slim/psr7) |
| setasign/tfpdf | 1.34 | LGPL-2.1 | PDF-Erzeugung (UTF-8) für den Kontoauszug |

## Mitgelieferte Web-Assets

| Paket | Version | Lizenz | Ort |
|---|---|---|---|
| Redoc (redoc.standalone.js) | 2.5.4 | MIT | `backend/public/api/docs/` – Lizenzen der eingebündelten Bibliotheken in `redoc.standalone.js.LICENSE.txt`, Redoc-Lizenz in `redoc-LICENSE.txt` |
| JetBrains Mono (Regular, Bold) 2.304 | OFL-1.1 | `backend/resources/fonts/` – Lizenztext in `JetBrainsMono-OFL.txt` (Kontoauszug-PDF) |

## Nur Entwicklung (nicht im Release-Paket)

| Paket | Version | Lizenz |
|---|---|---|
| phpunit/phpunit | 10.5.66 | BSD-3-Clause |
| phpunit/php-code-coverage | 10.1.16 | BSD-3-Clause |
| phpunit/php-file-iterator | 4.1.0 | BSD-3-Clause |
| phpunit/php-invoker | 4.0.0 | BSD-3-Clause |
| phpunit/php-text-template | 3.0.1 | BSD-3-Clause |
| phpunit/php-timer | 6.0.0 | BSD-3-Clause |
| sebastian/* (cli-parser 2.0.1, code-unit 2.0.0, code-unit-reverse-lookup 3.0.0, comparator 5.0.5, complexity 3.2.0, diff 5.1.1, environment 6.1.0, exporter 5.1.4, global-state 6.0.2, lines-of-code 2.0.2, object-enumerator 5.0.0, object-reflector 3.0.0, recursion-context 5.0.2, type 4.0.0, version 4.0.1) | s. links | BSD-3-Clause |
| nikic/php-parser | 5.9.0 | BSD-3-Clause |
| myclabs/deep-copy | 1.14.0 | MIT |
| phar-io/manifest | 2.0.4 | BSD-3-Clause |
| phar-io/version | 3.2.1 | BSD-3-Clause |
| theseer/tokenizer | 1.3.1 | BSD-3-Clause |
| Composer (composer.phar, nur Werkzeug in `backend/tools/`, nicht im Repo) | 2.10.3 | MIT |
