import { describe, it, expect } from 'vitest';
import { toGray, boxMean, percentiles, enhance } from '../src/lib/ocrPrep.js';

describe('ocrPrep (Vorverarbeitung fuer die lokale Erkennung)', () => {
  it('toGray: Luma aus RGBA', () => {
    expect(Array.from(toGray(new Uint8ClampedArray([255, 255, 255, 255, 0, 0, 0, 255])))).toEqual([255, 0]);
  });

  it('boxMean: konstantes Bild bleibt konstant, auch am Rand', () => {
    const m = boxMean(new Uint8ClampedArray(25).fill(100), 5, 5, 2);
    expect(Array.from(m).every((v) => Math.abs(v - 100) < 1e-6)).toBe(true);
  });

  it('percentiles: Grenzen aus dem Histogramm', () => {
    const g = new Uint8ClampedArray(100).fill(200); g[0] = 10;
    expect(percentiles(g, 0.005, 0.99)).toEqual([10, 200]);
  });

  it('enhance flat: ungleichmaessiger Hintergrund wird weiss, dunkle Tinte bleibt dunkel', () => {
    const w = 60; const h = 20;
    const g = new Uint8ClampedArray(w * h);
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) g[y * w + x] = 120 + x * 2; // Helligkeitsverlauf
    g[10 * w + 30] = 40; // Tintenpunkt
    const out = enhance(g, w, h, { mode: 'flat', radius: 12 });
    expect(out[10 * w + 5]).toBe(255);
    expect(out[10 * w + 55]).toBe(255);
    expect(out[10 * w + 30]).toBeLessThan(60);
  });

  it('enhance gray: spreizt den Kontrast', () => {
    const g = new Uint8ClampedArray(100); for (let i = 0; i < 100; i++) g[i] = 100 + i; // 100..199
    const out = enhance(g, 10, 10, { mode: 'gray' });
    expect(out[99]).toBeGreaterThan(240);
    expect(out[0]).toBeLessThan(15);
  });
});
