# Credits – Frontend (Quitt)

Quitt selbst steht unter der AGPL-3.0. Das Frontend (`frontend/`) nutzt folgende freie Bibliotheken.
Bei neuer Abhängigkeit sofort ergänzen (Konvention). Erzeugt aus `npm ls`, Stand der package-lock.json.

## Laufzeit (im ausgelieferten Build enthalten bzw. Build-Bestandteil)

| Paket | Version | Lizenz |
|---|---|---|
| @babel/helper-string-parser | 7.29.7 | MIT |
| @babel/helper-validator-identifier | 7.29.7 | MIT |
| @babel/parser | 7.29.9 | MIT |
| @babel/types | 7.29.8 | MIT |
| @jridgewell/sourcemap-codec | 1.6.0 | MIT |
| @vue/compiler-core | 3.5.44 | MIT |
| @vue/compiler-dom | 3.5.44 | MIT |
| @vue/compiler-sfc | 3.5.44 | MIT |
| @vue/compiler-ssr | 3.5.44 | MIT |
| @vue/devtools-api | 7.7.10 | MIT |
| @vue/devtools-kit | 7.7.10 | MIT |
| @vue/devtools-shared | 7.7.10 | MIT |
| @vue/reactivity | 3.5.44 | MIT |
| @vue/runtime-core | 3.5.44 | MIT |
| @vue/runtime-dom | 3.5.44 | MIT |
| @vue/server-renderer | 3.5.44 | MIT |
| @vue/shared | 3.5.44 | MIT |
| birpc | 2.9.0 | MIT |
| copy-anything | 4.1.5 | MIT |
| csstype | 3.2.3 | MIT |
| dom7 | 4.0.6 | MIT |
| entities | 7.0.1 | BSD-2-Clause |
| estree-walker | 2.0.2 | MIT |
| framework7 | 9.2.0 | MIT |
| framework7-icons | 5.0.5 | MIT |
| framework7-vue | 9.2.0 | MIT |
| hookable | 5.5.3 | MIT |
| htm | 3.1.1 | Apache-2.0 |
| idb | 8.0.4 | ISC |
| magic-string | 0.30.21 | MIT |
| mitt | 3.0.1 | MIT |
| nanoid | 3.3.20 | MIT |
| path-to-regexp | 6.3.0 | MIT |
| perfect-debounce | 1.0.0 | MIT |
| picocolors | 1.1.1 | ISC |
| pinia | 3.0.4 | MIT |
| postcss | 8.5.30 | MIT |
| rfdc | 1.4.1 | MIT |
| skeleton-elements | 4.0.1 | MIT |
| source-map-js | 1.2.2 | BSD-3-Clause |
| speakingurl | 14.0.1 | BSD-3-Clause |
| ssr-window | 4.0.2 | MIT |
| ssr-window | 5.0.1 | MIT |
| superjson | 2.2.6 | MIT |
| swiper | 12.2.0 | MIT |
| vue | 3.5.44 | MIT |

Hinweis: Pakete aus dem Vue-Compiler (`@vue/compiler-*`, `@babel/*`, `source-map-js` u. a.) werden nur beim Bauen genutzt; Pinia-Devtools-Pakete (`@vue/devtools-*`) sind im Produktions-Build wirkungslos.

## Entwicklung, Build & Tests (nicht im ausgelieferten Build)

| Paket | Version | Lizenz |
|---|---|---|
| @resvg/resvg-js | 2.6.2 | MPL-2.0 |
| @vitejs/plugin-vue | 6.0.10 | MIT |
| fake-indexeddb | 6.2.5 | Apache-2.0 |
| vite | 8.3.4 | MIT |
| vitest | 5.0.4 | MIT |

Transitive Abhängigkeiten der Entwicklungswerkzeuge: siehe `npm ls --all` in `frontend/`.

## Lokale Belegerkennung (REQ-011, wird bei Bedarf geladen)

Worker, WASM-Kern und Sprachdaten liegen im Build unter `ocr/` (kopiert von `scripts/copy-ocr.mjs`); zur Laufzeit wird nichts von einem CDN geladen.

| Paket | Version | Lizenz |
|---|---|---|
| tesseract.js | 7.0.0 | Apache-2.0 |
| tesseract.js-core (WASM-Kern, enthält Tesseract OCR der Tesseract-Autoren und Leptonica) | 7.0.0 | Apache-2.0 |
| @tesseract.js-data/deu (Sprachdaten „deu“, Variante 4.0.0_best_int, aus tessdata_best) | 1.0.0 | MIT (Paket); Trainingsdaten tessdata_best: Apache-2.0 |
| wasm-feature-detect | 1.9.0 | Apache-2.0 |
| idb-keyval | 6.3.0 | Apache-2.0 |
| bmp-js | 0.1.0 | MIT |
| is-url | 1.2.4 | MIT |
| node-fetch | 2.7.0 | MIT |
| opencollective-postinstall | 2.0.3 | MIT |
| regenerator-runtime | 0.13.11 | MIT |
| zlibjs | 0.3.1 | MIT |

## Grafiken & Schriften

| Element | Herkunft | Lizenz |
|---|---|---|
| Framework7 Icons (Schrift) | framework7-icons | MIT |
| Quitt-Logo, App-Icons (`public/icons/`, aus `src/assets/logo.svg` erzeugt) | eigenes Werk | AGPL-3.0 (mit Quitt) |
| Systemschrift (SF / Roboto) | Betriebssystem, nicht mitgeliefert | – |
