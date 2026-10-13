import { describe, it, expect } from 'vitest';
import { RESIZE, targetSize, attemptPlan } from '../src/lib/image.js';

describe('Bildverkleinerung – Parameter', () => {
  it('Vorgaben: Kante 1600, JPEG 0.7, Ziel 500 KB (Server-Limit 5 MB weit unterschritten)', () => {
    expect(RESIZE.maxEdge).toBe(1600);
    expect(RESIZE.quality).toBe(0.7);
    expect(RESIZE.mime).toBe('image/jpeg');
    expect(RESIZE.targetBytes).toBe(500 * 1024);
    expect(RESIZE.targetBytes).toBeLessThan(5 * 1024 * 1024);
  });
  it('laengste Kante wird auf 1600 begrenzt, Seitenverhaeltnis bleibt (Hoch- und Querformat)', () => {
    expect(targetSize(4032, 3024)).toEqual({ width: 1600, height: 1200 });
    expect(targetSize(3024, 4032)).toEqual({ width: 1200, height: 1600 });
  });
  it('kleine Bilder werden nie vergroessert', () => {
    expect(targetSize(800, 600)).toEqual({ width: 800, height: 600 });
  });
  it('Versuchsplan: erst Qualitaet 0.7 -> 0.4 bei 1600 px, dann Kante schrittweise bis 800 px', () => {
    const plan = attemptPlan(4032, 3024);
    expect(plan[0]).toEqual({ width: 1600, height: 1200, quality: 0.7 });
    expect(plan.slice(0, 4).map((p) => p.quality)).toEqual([0.7, 0.6, 0.5, 0.4]);
    expect(plan.slice(0, 4).every((p) => p.width === 1600)).toBe(true);
    expect(plan[4]).toEqual({ width: 1360, height: 1020, quality: 0.7 });
    const last = plan.at(-1);
    expect(Math.max(last.width, last.height)).toBe(RESIZE.minEdge);
    expect(last.quality).toBe(0.4);
    // monoton kleiner werdende Kanten
    const edges = plan.map((p) => Math.max(p.width, p.height));
    expect(edges.every((e, i) => i === 0 || e <= edges[i - 1])).toBe(true);
  });
});
