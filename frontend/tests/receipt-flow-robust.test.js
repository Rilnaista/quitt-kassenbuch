import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { reactive, ref } from 'vue';
import * as db from '../src/db/index.js';
import * as repo from '../src/db/repo.js';
import { useReceiptQueue } from '../src/stores/receipts.js';
import { createPinia, setActivePinia } from 'pinia';
import { withTimeout, decode, shrinkImage, StepError } from '../src/lib/image.js';

beforeEach(async () => { await db._resetDb(); });

describe('IndexedDB: nur klonbare Daten', () => {
  it('Vue-Proxys scheitern roh mit DataCloneError, db.put/tx bereinigen sie', async () => {
    const proxy = reactive({ key: 'k', value: ['a', 'b'], nested: { x: ref(1).value } });
    const raw = await db.getDb();
    await expect(raw.put('meta', proxy)).rejects.toThrow();
    await db.put('meta', proxy);
    expect(await db.get('meta', 'k')).toEqual({ key: 'k', value: ['a', 'b'], nested: { x: 1 } });
    await db.tx(['meta'], (t) => t.objectStore('meta').put(reactive({ key: 'k2', value: reactive([1, 2]), fn: () => 1 })));
    expect(await db.get('meta', 'k2')).toEqual({ key: 'k2', value: [1, 2] });
  });
  it('Pinia-Warteschlange (Ursache des iOS-Haengers) speichert reaktive Listen', async () => {
    setActivePinia(createPinia());
    const q = useReceiptQueue();
    await q.add('r1');
    await q.add('r2');
    expect(await db.getMeta('pendingReceipts')).toEqual(['r1', 'r2']);
  });
  it('saveEntry mit reaktiver Eingabe', async () => {
    const e = reactive({ type: 'out', date: '2026-10-01', amount_cents: 100, reason: 'a', category: 'b', item: 'c' });
    const saved = await repo.saveEntry(e);
    expect((await db.get('entries', saved.id)).item).toBe('c');
  });
  it('Blobs bleiben unveraendert; addReceipt speichert Blob', async () => {
    const id = await repo.addReceipt(new Blob(['abc'], { type: 'image/jpeg' }));
    const b = await repo.getReceiptBlob(id);
    expect(b.size).toBe(3);
  });
  it('Fallback: scheitert der Blob-put, wird ArrayBuffer + mime gespeichert und als Blob gelesen', async () => {
    const raw = await db.getDb();
    const orig = raw.transaction.bind(raw);
    let failed = false;
    raw.transaction = (...a) => {
      const t = orig(...a);
      const os = t.objectStore.bind(t);
      t.objectStore = (n) => {
        const s = os(n);
        if (n !== 'blobs') return s;
        return new Proxy(s, { get(st, p) { return p === 'put' ? (v) => { if (v.blob && !failed) { failed = true; throw new DOMException('The object can not be cloned.', 'DataCloneError'); } return st.put(v); } : (typeof st[p] === 'function' ? st[p].bind(st) : st[p]); } });
      };
      return t;
    };
    const id = await repo.addReceipt(new Blob(['abcd'], { type: 'image/png' }));
    raw.transaction = orig;
    expect(failed).toBe(true);
    const rec = await db.get('blobs', id);
    expect(rec.mime).toBe('image/png');
    expect(rec.blob.size).toBe(4);
    expect(rec.blob.type).toBe('image/png');
  });
});

describe('Zeitlimit-Wrapper', () => {
  afterEach(() => vi.useRealTimers());
  it('liefert Ergebnis, wenn rechtzeitig', async () => {
    await expect(withTimeout(Promise.resolve(5), 100, 'x')).resolves.toBe(5);
  });
  it('wirft StepError mit Schritt bei Zeitueberschreitung', async () => {
    vi.useFakeTimers();
    const p = withTimeout(new Promise(() => {}), 20000, 'decode');
    const check = expect(p).rejects.toMatchObject({ name: 'StepError', step: 'decode', info: { timeout: true } });
    await vi.advanceTimersByTimeAsync(20001);
    await check;
  });
});

describe('Fallback-Kette decode/shrinkImage (gemockt)', () => {
  const file = (type = 'image/jpeg', size = 1000) => Object.assign(new Blob([new Uint8Array(size)], { type }));
  afterEach(() => { vi.unstubAllGlobals(); });

  it('createImageBitmap mit Optionen scheitert -> ohne Optionen', async () => {
    const calls = [];
    vi.stubGlobal('createImageBitmap', async (f, o) => { calls.push(o); if (o) throw new Error('opts'); return { width: 10, height: 10 }; });
    const r = await decode(file());
    expect(r.width).toBe(10);
    expect(calls).toHaveLength(2);
  });
  it('haengt createImageBitmap, greift <img> nach Zeitlimit', async () => {
    vi.stubGlobal('createImageBitmap', () => new Promise(() => {}));
    vi.stubGlobal('URL', { createObjectURL: () => 'blob:x', revokeObjectURL() {} });
    vi.stubGlobal('Image', class { set src(v) { setTimeout(() => this.onload && this.onload(), 0); } get width() { return 7; } });
    const r = await decode(file(), 30);
    expect(r.width).toBe(7);
  });
  it('alles scheitert: StepError decode', async () => {
    vi.stubGlobal('createImageBitmap', async () => { throw new Error('nope'); });
    vi.stubGlobal('URL', { createObjectURL: () => 'blob:x', revokeObjectURL() {} });
    vi.stubGlobal('Image', class { set src(v) { setTimeout(() => this.onerror && this.onerror(), 0); } });
    await expect(decode(file(), 30)).rejects.toBeInstanceOf(StepError);
  });
  it('Verkleinern scheitert: JPEG-Original <= 5 MB wird verwendet', async () => {
    vi.stubGlobal('createImageBitmap', async () => { throw new Error('nope'); });
    vi.stubGlobal('URL', { createObjectURL: () => 'blob:x', revokeObjectURL() {} });
    vi.stubGlobal('Image', class { set src(v) { setTimeout(() => this.onerror && this.onerror(), 0); } });
    const f = file('image/jpeg', 2 * 1024 * 1024);
    const r = await shrinkImage(f, undefined, 30);
    expect(r.blob).toBe(f);
    expect(r.fallback).toBe(true);
    expect(r.info).toMatchObject({ type: 'image/jpeg', size: 2 * 1024 * 1024 });
  });
  it('Verkleinern scheitert bei HEIC oder > 5 MB: StepError mit Details', async () => {
    vi.stubGlobal('createImageBitmap', async () => { throw new Error('nope'); });
    vi.stubGlobal('URL', { createObjectURL: () => 'blob:x', revokeObjectURL() {} });
    vi.stubGlobal('Image', class { set src(v) { setTimeout(() => this.onerror && this.onerror(), 0); } });
    await expect(shrinkImage(file('image/heic', 100), undefined, 30)).rejects.toMatchObject({ step: 'decode', info: { type: 'image/heic', size: 100 } });
    await expect(shrinkImage(file('image/jpeg', 6 * 1024 * 1024), undefined, 30)).rejects.toBeInstanceOf(StepError);
  });
  it('toBlob liefert null/haengt: Zeitlimit statt Dauerzustand, danach Original', async () => {
    vi.stubGlobal('createImageBitmap', async () => ({ width: 4032, height: 3024, close() {} }));
    vi.stubGlobal('document', { createElement: () => ({ getContext: () => ({ fillRect() {}, drawImage() {} }), toBlob() { /* haengt */ } }) });
    const f = file('image/jpeg', 3 * 1024 * 1024);
    const r = await shrinkImage(f, undefined, 30);
    expect(r.fallback).toBe(true);
    expect(r.step).toBe('toBlob');
  });
});
