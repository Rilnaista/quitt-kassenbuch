import { describe, it, expect, vi } from 'vitest';
import { presetRange, validRange, summarize } from '../src/lib/statement.js';
import { shouldCheck, silentCheck, storedAvailable, DAY_MS } from '../src/lib/updateCheck.js';
import { createReporter } from '../src/lib/clientLog.js';
import { sortNewest } from '../src/lib/logs.js';
import { buildLedger } from '../src/lib/ledger.js';
import { HELP_TOPICS, HELP_CONTEXTS } from '../src/lib/help.js';
import { t } from '../src/i18n/index.js';

describe('Kontoauszug-Zeitraum', () => {
  const now = new Date(2026, 9, 13); // 13.10.2026
  it('Voreinstellungen', () => {
    expect(presetRange('thisYear', now)).toEqual({ from: '2026-01-01', to: '2026-10-13' });
    expect(presetRange('lastMonth', now)).toEqual({ from: '2026-09-01', to: '2026-09-30' });
    expect(presetRange('all', now, '2025-03-02')).toEqual({ from: '2025-03-02', to: '2026-10-13' });
    expect(presetRange('all', now, null).from).toBe('2026-01-01');
  });
  it('letzter Monat ueber Jahreswechsel und Schaltjahr', () => {
    expect(presetRange('lastMonth', new Date(2026, 0, 5))).toEqual({ from: '2025-12-01', to: '2025-12-31' });
    expect(presetRange('lastMonth', new Date(2024, 2, 10))).toEqual({ from: '2024-02-01', to: '2024-02-29' });
  });
  it('Zeitraum-Pruefung', () => {
    expect(validRange('2026-01-01', '2026-01-01')).toBe(true);
    expect(validRange('2026-02-01', '2026-01-01')).toBe(false);
    expect(validRange('', '2026-01-01')).toBe(false);
  });
  it('Kurzvorschau: Anfangs-/Endsaldo', () => {
    const l = buildLedger([
      { id: 'a', type: 'in', amount_cents: 1000, date: '2026-01-05' },
      { id: 'b', type: 'out', amount_cents: 300, date: '2026-02-10' },
      { id: 'c', type: 'out', amount_cents: 100, date: '2026-03-10' },
    ]);
    expect(summarize(l, '2026-02-01', '2026-02-28')).toEqual({ opening: 1000, closing: 700, count: 1 });
    expect(summarize(l, '2026-01-01', '2026-12-31')).toEqual({ opening: 0, closing: 600, count: 3 });
  });
});

describe('Update-Pruefung (Drosselung)', () => {
  const store = () => { const m = new Map(); return { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) }; };
  it('hoechstens 1x pro Tag', () => {
    expect(shouldCheck(0, 1000)).toBe(true);
    expect(shouldCheck(1000, 1000 + DAY_MS - 1)).toBe(false);
    expect(shouldCheck(1000, 1000 + DAY_MS)).toBe(true);
    expect(shouldCheck(5000, 1000)).toBe(true); // Uhr zurueck
  });
  it('silentCheck fragt nur einmal und merkt Hinweis', async () => {
    const st = store();
    const fn = vi.fn(async () => ({ available: true, latest: '1.2.0', docker: false }));
    expect(await silentCheck(fn, { now: 10_000, storage: st })).toMatchObject({ latest: '1.2.0' });
    expect(await silentCheck(fn, { now: 20_000, storage: st })).toBeNull();
    expect(fn).toHaveBeenCalledTimes(1);
    expect(storedAvailable(st)).toEqual({ latest: '1.2.0' });
    await silentCheck(async () => ({ available: false, latest: '1.2.0' }), { now: 10_000 + DAY_MS, storage: st });
    expect(storedAvailable(st)).toBeNull();
  });
  it('Docker: kein Hinweis-Punkt; Fehler still und ohne Merken', async () => {
    const st = store();
    await silentCheck(async () => ({ available: true, latest: '2', docker: true }), { now: 1, storage: st });
    expect(storedAvailable(st)).toBeNull();
    const st2 = store();
    expect(await silentCheck(async () => { throw new Error('offline'); }, { now: 1, storage: st2 })).toBeNull();
    expect(shouldCheck(Number(st2.getItem('quitt.updateLastCheck')), 2)).toBe(true);
  });
});

describe('Frontend-Fehler melden (gedrosselt)', () => {
  it('Mindestabstand, Dubletten, Obergrenze', () => {
    let now = 0;
    const send = vi.fn(async () => {});
    const r = createReporter(send, { minGap: 5000, maxTotal: 2, now: () => now });
    expect(r('a')).toBe(true);
    now = 1000; expect(r('b')).toBe(false);
    now = 6000; expect(r('a')).toBe(false); // Dublette
    now = 7000; expect(r('b')).toBe(true);
    now = 20000; expect(r('c')).toBe(false); // Obergrenze
    expect(send).toHaveBeenCalledTimes(2);
  });
});

describe('Protokoll & Hilfe', () => {
  it('neueste oben', () => {
    expect(sortNewest([{ ts: '2026-01-01T10:00:00Z' }, { ts: '2026-01-02T10:00:00Z' }]).map((r) => r.ts[9])).toEqual(['2', '1']);
  });
  it('zu jedem Hilfethema und -kontext gibt es Texte', () => {
    for (const k of HELP_TOPICS) { expect(t(`help.topics.${k}.q`)).not.toBe(`help.topics.${k}.q`); expect(t(`help.topics.${k}.a`).length).toBeGreaterThan(20); }
    for (const k of [...HELP_CONTEXTS, 'generic']) expect(t(`help.ctx.${k}.text`)).not.toBe(`help.ctx.${k}.text`);
  });
});
