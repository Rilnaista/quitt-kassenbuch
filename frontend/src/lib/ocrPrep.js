// Vorverarbeitung fuer die lokale Texterkennung (Canvas, ohne Abhaengigkeit).
// Ziel: Fotos von Bons (schiefes Licht, Schatten, Papierstruktur, Rauschen) so aufbereiten, dass tesseract
// sauberen Text bekommt: Graustufen -> Hintergrund-Angleichung (Division durch lokalen Mittelwert, Integralbild)
// -> Kontrastspreizung. MESSUNG (scripts/ocr-eval.mjs, 10 Test-Bons): fuer die gebuendelte LSTM-Engine bringt das auf den
// Test-Bons nichts (gray: -1 %, flat/Schwelle: stark schlechter, weil Papierkorn/Rauschen mitgelesen wird) -> Standard
// ist 'none' (siehe OCR_DEFAULTS); die Funktionen bleiben fuer Nachmessungen mit anderen Fotos. Die
// Kernfunktionen arbeiten auf Uint8ClampedArray (testbar).

/** RGBA -> Graustufen (Luma). */
export function toGray(rgba, n = rgba.length / 4) {
  const g = new Uint8ClampedArray(n);
  for (let i = 0, j = 0; i < n; i++, j += 4) g[i] = (rgba[j] * 299 + rgba[j + 1] * 587 + rgba[j + 2] * 114) / 1000;
  return g;
}

/** Lokaler Mittelwert (Boxfilter, Radius r) ueber Integralbild. */
export function boxMean(gray, w, h, r) {
  const W = w + 1;
  const integ = new Float64Array(W * (h + 1));
  for (let y = 0; y < h; y++) {
    let row = 0;
    for (let x = 0; x < w; x++) {
      row += gray[y * w + x];
      integ[(y + 1) * W + x + 1] = integ[y * W + x + 1] + row;
    }
  }
  const out = new Float32Array(w * h);
  for (let y = 0; y < h; y++) {
    const y0 = Math.max(0, y - r); const y1 = Math.min(h, y + r + 1);
    for (let x = 0; x < w; x++) {
      const x0 = Math.max(0, x - r); const x1 = Math.min(w, x + r + 1);
      const s = integ[y1 * W + x1] - integ[y0 * W + x1] - integ[y1 * W + x0] + integ[y0 * W + x0];
      out[y * w + x] = s / ((x1 - x0) * (y1 - y0));
    }
  }
  return out;
}

/** Perzentil-Grenzen (lo/hi in 0..1) aus dem Histogramm. */
export function percentiles(gray, lo = 0.01, hi = 0.99) {
  const hist = new Uint32Array(256);
  for (let i = 0; i < gray.length; i++) hist[gray[i]]++;
  let acc = 0; let a = 0; let b = 255;
  for (let v = 0; v < 256; v++) { acc += hist[v]; if (acc >= gray.length * lo) { a = v; break; } }
  acc = 0;
  for (let v = 0; v < 256; v++) { acc += hist[v]; if (acc >= gray.length * hi) { b = v; break; } }
  return [a, b];
}

/**
 * Graustufenbild aufbereiten. mode: 'gray' (nur Kontrastspreizung) oder 'flat' (Hintergrund-Angleichung). radius: Fensterradius in px (deutlich groesser als die Schrifthoehe).
 */
export function enhance(gray, w, h, { mode = 'flat', radius, black = 0.45, white = 0.85 } = {}) {
  const out = new Uint8ClampedArray(gray.length);
  if (mode === 'gray') {
    const [a, b] = percentiles(gray);
    const k = 255 / Math.max(1, b - a);
    for (let i = 0; i < gray.length; i++) out[i] = (gray[i] - a) * k;
    return out;
  }
  const r = radius || Math.max(12, Math.round(Math.min(w, h) / 30));
  const bg = boxMean(gray, w, h, r);
  // Verhaeltnis Pixel/lokaler Hintergrund: Papier ~1, Tinte deutlich darunter. Alles ab `white` wird weiss
  // (unterdrueckt Papierkorn und Rauschen), alles unter `black` schwarz, dazwischen linear (Kanten bleiben weich).
  const lo = black; const span = Math.max(0.05, white - black);
  for (let i = 0; i < gray.length; i++) {
    const ratio = gray[i] / Math.max(24, bg[i]);
    const v = (ratio - lo) / span;
    out[i] = v * 255;
  }
  return out;
}

/** Bild (Blob/File) -> aufbereitetes Canvas. scale: Faktor fuer die Groesse (>1 vergroessert). */
export async function prepareForOcr(blob, { mode = 'flat', scale = 1, maxPixels = 8e6 } = {}) {
  const bmp = await createImageBitmap(blob, { imageOrientation: 'from-image' }).catch(() => createImageBitmap(blob));
  try {
    let s = scale;
    if (bmp.width * bmp.height * s * s > maxPixels) s = Math.sqrt(maxPixels / (bmp.width * bmp.height));
    const w = Math.round(bmp.width * s); const h = Math.round(bmp.height * s);
    const canvas = document.createElement('canvas');
    canvas.width = w; canvas.height = h;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, w, h);
    ctx.drawImage(bmp, 0, 0, w, h);
    const img = ctx.getImageData(0, 0, w, h);
    const g = enhance(toGray(img.data, w * h), w, h, { mode });
    for (let i = 0, j = 0; i < g.length; i++, j += 4) { img.data[j] = img.data[j + 1] = img.data[j + 2] = g[i]; img.data[j + 3] = 255; }
    ctx.putImageData(img, 0, 0);
    return canvas;
  } finally { if (bmp.close) bmp.close(); }
}
