// Bildverkleinerung im Browser vor dem Hochladen (Beleg max. 5 MB serverseitig).
// Ziel: laengste Kante <= 1600 px, JPEG, Qualitaet ab 0.7 abwaerts, bis <= 500 KB.
// EXIF-Ausrichtung: createImageBitmap(..., { imageOrientation: 'from-image' }) bzw. die
// in allen aktuellen Browsern standardmaessig angewandte Orientierung (CSS image-orientation).

export const RESIZE = Object.freeze({
  maxEdge: 1600,
  quality: 0.7,
  minQuality: 0.4,
  qualityStep: 0.1,
  targetBytes: 500 * 1024,
  shrinkFactor: 0.85, // wenn Qualitaet am Minimum: Kante weiter verkleinern
  minEdge: 800,
  mime: 'image/jpeg',
});

/** Zielmasse bei gegebener Ausgangsgroesse: Seitenverhaeltnis bleibt, nie vergroessern. */
export function targetSize(width, height, maxEdge = RESIZE.maxEdge) {
  const edge = Math.max(width, height);
  if (!edge || edge <= maxEdge) return { width, height };
  const f = maxEdge / edge;
  return { width: Math.round(width * f), height: Math.round(height * f) };
}

/**
 * Reihenfolge der Versuche (Kante, Qualitaet), bis das Ergebnis <= targetBytes ist.
 * Erst Qualitaet senken (0.7 -> 0.4), dann Kante schrittweise verkleinern.
 */
export function attemptPlan(width, height, opts = RESIZE) {
  const plan = [];
  let edge = Math.min(opts.maxEdge, Math.max(width, height));
  for (;;) {
    for (let q = opts.quality; q >= opts.minQuality - 1e-9; q -= opts.qualityStep) {
      plan.push({ ...targetSize(width, height, edge), quality: Math.round(q * 100) / 100 });
    }
    if (edge <= opts.minEdge) break;
    edge = Math.max(opts.minEdge, Math.round(edge * opts.shrinkFactor));
  }
  return plan;
}

export const STEP_TIMEOUT_MS = 20000;
export const MAX_CANVAS_PIXELS = 16 * 1000 * 1000; // iOS-Canvas-Grenze ~16,7 MP
const ORIGINAL_MAX_BYTES = 5 * 1024 * 1024;
const OK_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/** Fehler eines Verarbeitungsschritts: step = decode | toBlob | save | ..., info = Diagnosedaten fuers Log. */
export class StepError extends Error {
  constructor(step, message, info = {}, cause) {
    super(message);
    this.name = 'StepError';
    this.step = step;
    this.info = info;
    this.cause = cause;
  }
}

/** Bricht einen Schritt nach ms ab (auf iOS koennen decode()/toBlob() ohne Ergebnis haengen). */
export function withTimeout(promise, ms, step) {
  let timer;
  const limit = new Promise((_, reject) => { timer = setTimeout(() => reject(new StepError(step, `Zeitueberschreitung (${step}, ${ms} ms)`, { timeout: true })), ms); });
  return Promise.race([Promise.resolve(promise), limit]).finally(() => clearTimeout(timer));
}

function decodeViaImg(file) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('img onerror')); };
    img.src = url;
  });
}

/** Kette: createImageBitmap mit Optionen -> ohne Optionen -> <img> (onload/onerror). Jeder Versuch mit Zeitlimit. */
export async function decode(file, ms = STEP_TIMEOUT_MS) {
  const attempts = [];
  if (typeof createImageBitmap === 'function') {
    attempts.push(() => createImageBitmap(file, { imageOrientation: 'from-image' }));
    attempts.push(() => createImageBitmap(file));
  }
  attempts.push(() => decodeViaImg(file));
  let last;
  for (const run of attempts) {
    try { return await withTimeout(run(), ms, 'decode'); } catch (e) { last = e; }
  }
  throw new StepError('decode', `Bild nicht dekodierbar: ${last && last.message}`, last && last.info, last);
}

function toBlob(canvas, quality, ms) {
  return withTimeout(new Promise((resolve, reject) => {
    try { canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('toBlob lieferte null'))), RESIZE.mime, quality); } catch (e) { reject(e); }
  }), ms, 'toBlob');
}

async function shrinkCore(file, opts, ms, dims) {
  const src = await decode(file, ms);
  try {
    const w = src.width || src.naturalWidth;
    const h = src.height || src.naturalHeight;
    dims.width = w; dims.height = h;
    if (OK_TYPES.includes(file.type) && file.size <= opts.targetBytes && Math.max(w, h) <= opts.maxEdge) {
      return { blob: file, width: w, height: h, mime: file.type };
    }
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    if (!ctx) throw new StepError('toBlob', 'Canvas nicht verfuegbar');
    let last = null;
    for (const step of attemptPlan(w, h, opts)) {
      if (step.width * step.height > MAX_CANVAS_PIXELS) continue;
      if (canvas.width !== step.width || canvas.height !== step.height) {
        canvas.width = step.width;
        canvas.height = step.height;
        ctx.fillStyle = '#fff'; // transparente PNGs nicht schwarz werden lassen
        ctx.fillRect(0, 0, step.width, step.height);
        ctx.drawImage(src, 0, 0, step.width, step.height);
      }
      const blob = await toBlob(canvas, step.quality, ms);
      last = { blob, width: step.width, height: step.height, mime: opts.mime };
      if (blob.size <= opts.targetBytes) break;
    }
    if (!last) throw new StepError('toBlob', 'Kein zulaessiges Zielformat');
    return last;
  } finally {
    if (src.close) src.close();
  }
}

/**
 * Verkleinert ein Bild (File/Blob). Liefert { blob, width, height, mime }.
 * Ist das Original bereits klein genug (JPEG/PNG/WebP, <= Ziel und Kante ok), bleibt es unveraendert.
 * Scheitert das Verkleinern, wird ein JPEG/PNG/WebP-Original <= 5 MB unveraendert verwendet (fallback: true);
 * sonst wirft die Funktion einen StepError (step, info = Dateityp/-groesse/Bildmasse).
 */
export async function shrinkImage(file, opts = RESIZE, ms = STEP_TIMEOUT_MS) {
  const dims = {};
  try {
    return await shrinkCore(file, opts, ms, dims);
  } catch (e) {
    const info = { ...(e.info || {}), type: file.type, size: file.size, ...dims, error: e && e.message };
    if (OK_TYPES.includes(file.type) && file.size <= ORIGINAL_MAX_BYTES) {
      return { blob: file, width: dims.width || 0, height: dims.height || 0, mime: file.type, fallback: true, reason: e && e.message, step: e && e.step, info };
    }
    throw new StepError(e && e.step ? e.step : 'shrink', e && e.message, info, e);
  }
}
