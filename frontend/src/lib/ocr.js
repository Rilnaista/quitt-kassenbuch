// Lokale Texterkennung (tesseract.js, Sprache Deutsch). Worker, WASM-Kern und Sprachdaten liegen
// gebuendelt unter <app>/ocr/ (scripts/copy-ocr.mjs) – kein CDN. tesseract.js wird erst bei Bedarf geladen;
// der Service Worker haelt die Dateien nach dem ersten Abruf im Cache (offline nutzbar).

import { prepareForOcr } from './ocrPrep.js';

export const OCR_FILES = [
  'worker.min.js', 'tesseract-core-lstm.wasm.js', 'tesseract-core-simd-lstm.wasm.js',
  'tesseract-core-relaxedsimd-lstm.wasm.js', 'deu.traineddata.gz',
];

const ocrBase = () => new URL('ocr/', document.baseURI).href;

/** Optionen: preprocess ('none'|'gray'|'flat'), scale, psm. Standard siehe OCR_DEFAULTS. */
export const OCR_DEFAULTS = Object.freeze({ preprocess: 'none', scale: 1, psm: undefined });

/** Text aus einem Bild lesen. onProgress(0..1). Der Worker wird danach beendet (Speicher auf Handys knapp). */
export async function recognizeText(image, onProgress = () => {}, options = {}) {
  const opts = { ...OCR_DEFAULTS, ...options };
  if (opts.preprocess !== 'none') {
    try { image = await prepareForOcr(image, { mode: opts.preprocess, scale: opts.scale }); } catch (e) { /* ohne Vorverarbeitung weiter */ }
  }
  const { createWorker } = await import('tesseract.js');
  const base = ocrBase();
  const worker = await createWorker('deu', 1, {
    workerPath: base + 'worker.min.js',
    corePath: base,
    langPath: base,
    gzip: true,
    cacheMethod: 'none',
    workerBlobURL: false,
    logger: (m) => { if (m.status === 'recognizing text') onProgress(m.progress || 0); },
  });
  try {
    if (opts.psm) await worker.setParameters({ tessedit_pageseg_mode: String(opts.psm) });
    const { data } = await worker.recognize(image);
    return data.text || '';
  } finally {
    await worker.terminate();
  }
}

/** Dateien einmal anfordern, damit der Service Worker sie fuer die Offline-Nutzung ablegt. Fehler still. */
export async function warmOcrCache() {
  try {
    const base = ocrBase();
    for (const f of OCR_FILES) await fetch(base + f);
  } catch (e) { /* offline o. ae.: beim naechsten Mal */ }
}
