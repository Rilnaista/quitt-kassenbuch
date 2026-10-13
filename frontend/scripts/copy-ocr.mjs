// Kopiert Worker, WASM-Kern und deutsche Sprachdaten fuer die lokale Texterkennung (tesseract.js)
// nach public/ocr/ – alles wird mit der App ausgeliefert, zur Laufzeit wird nichts von einem CDN geladen.
// Aufruf automatisch ueber predev/prebuild. public/ocr/ ist nicht eingecheckt (grosse Binaerdateien).
import { cpSync, mkdirSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';

const root = fileURLToPath(new URL('..', import.meta.url));
const nm = join(root, 'node_modules');
const out = join(root, 'public', 'ocr');
mkdirSync(out, { recursive: true });

const files = [
  ['tesseract.js/dist/worker.min.js', 'worker.min.js'],
  // nur LSTM-Kerne (Standard-OCR-Engine); der Browser waehlt je nach SIMD-Unterstuetzung
  ['tesseract.js-core/tesseract-core-lstm.wasm.js', 'tesseract-core-lstm.wasm.js'],
  ['tesseract.js-core/tesseract-core-simd-lstm.wasm.js', 'tesseract-core-simd-lstm.wasm.js'],
  ['tesseract.js-core/tesseract-core-relaxedsimd-lstm.wasm.js', 'tesseract-core-relaxedsimd-lstm.wasm.js'],
  ['@tesseract.js-data/deu/4.0.0_best_int/deu.traineddata.gz', 'deu.traineddata.gz'],
];
for (const [from, to] of files) {
  const src = join(nm, from);
  if (!existsSync(src)) throw new Error('Fehlt: ' + from + ' (npm install ausgefuehrt?)');
  cpSync(src, join(out, to));
}
console.log('OCR-Dateien nach public/ocr/ kopiert');
