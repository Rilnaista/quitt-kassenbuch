import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setApiBase, setToken, clearToken } from '../src/api/index.js';
import * as db from '../src/db/index.js';
import * as repo from '../src/db/repo.js';
import { createSyncEngine } from '../src/sync/engine.js';
import { mockFetch, json, apiError } from './helpers.js';

const pull = (over = {}) => ({ entries: [], receipts: [], purged: { entries: [], receipts: [] }, cursor: 0, server_time: '2026-10-13T10:00:00Z', ...over });
const serverize = (e, number, version) => {
  const { _error, ...rest } = e; // eslint-disable-line no-unused-vars
  return { ...rest, number, version };
};
const out = (o = {}) => ({ type: 'out', date: '2026-10-01', amount_cents: 1290, reason: 'Geburtstag', category: 'Team Sonne', item: 'Blumen', ...o });

let statuses; let events;
function engine(opts = {}) {
  statuses = []; events = [];
  return createSyncEngine({ isOnline: () => true, canWrite: () => true, onStatus: (s) => statuses.push(s), onEvent: (e) => events.push(e), ...opts });
}

beforeEach(async () => {
  await db._resetDb();
  localStorage.clear();
  setApiBase('http://quitt.test/api/v1/');
  setToken('tok-1', '2027-10-13T00:00:00Z');
});

describe('Sync-Engine', () => {
  it('schickt lokale Aenderung (ohne number/version, mit Bearer), uebernimmt applied und setzt Cursor aus GET', async () => {
    const e = await repo.saveEntry(out());
    const calls = mockFetch({
      'POST /sync': (c) => ({ applied: c.body.entries.map((x) => serverize(x, 1, 7)), conflicts: [], cursor: 7 }),
      'GET /sync': () => pull({ entries: [serverize({ ...e }, 1, 7)], cursor: 7 }),
    });
    await engine().sync();

    const post = calls.find((c) => c.method === 'POST');
    expect(post.headers.Authorization).toBe('Bearer tok-1');
    expect(post.body.entries).toHaveLength(1);
    expect(post.body.entries[0]).not.toHaveProperty('number');
    expect(post.body.entries[0]).not.toHaveProperty('version');
    expect(post.body.entries[0]).toMatchObject({ id: e.id, type: 'out', amount_cents: 1290, category: 'Team Sonne', purpose: null });
    expect(calls.find((c) => c.method === 'GET').query.since).toBe('0');

    expect(await db.count('outbox')).toBe(0);
    expect((await db.get('entries', e.id)).number).toBe(1);
    expect(await db.getMeta('cursor')).toBe(7);
    expect(statuses.at(-1)).toMatchObject({ state: 'synced', pending: 0 });
  });

  it('nutzt beim naechsten Lauf den gespeicherten since-Cursor', async () => {
    await db.setMeta('cursor', 42);
    const calls = mockFetch({ 'GET /sync': () => pull({ cursor: 50 }) });
    await engine().sync();
    expect(calls[0].query.since).toBe('42');
    expect(await db.getMeta('cursor')).toBe(50);
  });

  it('laedt Belege VOR den Buchungen hoch (multipart "file")', async () => {
    const rid = await repo.addReceipt(new Blob([new Uint8Array([1, 2, 3])], { type: 'image/jpeg' }), 'image/jpeg');
    await repo.saveEntry(out({ receipt_id: rid }));
    const calls = mockFetch({
      'PUT /receipts/*': (c) => {
        expect(c.body).toBeInstanceOf(FormData);
        expect(c.body.get('file')).toBeTruthy();
        return json({ id: rid, mime: 'image/jpeg', size: 3, created_at: 'x', updated_at: 'x', deleted_at: null, version: 3 }, 201);
      },
      'POST /sync': (c) => ({ applied: c.body.entries.map((x) => serverize(x, 1, 4)), conflicts: [], cursor: 4 }),
      'GET /sync': () => pull({ cursor: 4 }),
    });
    await engine().sync();
    expect(calls.map((c) => c.method + ' ' + c.path.split('/')[1])).toEqual(['PUT receipts', 'POST sync', 'GET sync']);
    expect(await db.count('uploads')).toBe(0);
    expect((await db.get('receipts', rid)).version).toBe(3);
  });

  it('haelt Buchungen zurueck, solange ihr Beleg nicht hochgeladen ist (kein 422)', async () => {
    const rid = await repo.addReceipt(new Blob([new Uint8Array([1])], { type: 'image/jpeg' }));
    await repo.saveEntry(out({ receipt_id: rid }));
    const other = await repo.saveEntry(out({ item: 'Karte' }));
    const calls = mockFetch({
      'PUT /receipts/*': () => apiError(422, 'validation_failed'),
      'POST /sync': (c) => ({ applied: c.body.entries.map((x) => serverize(x, 2, 5)), conflicts: [], cursor: 5 }),
      'GET /sync': () => pull({ cursor: 5 }),
    });
    await engine().sync();
    const post = calls.find((c) => c.method === 'POST');
    expect(post.body.entries.map((x) => x.id)).toEqual([other.id]);
    expect(await db.count('outbox')).toBe(1);
    expect((await db.get('uploads', rid))._error).toBeTruthy();
  });

  it('uebernimmt bei conflicts den Server-Stand (LWW) und meldet das', async () => {
    const e = await repo.saveEntry(out());
    const server = serverize({ ...e, item: 'Vom anderen Gerät', updated_at: '2099-01-01T00:00:00Z' }, 3, 9);
    mockFetch({
      'POST /sync': () => ({ applied: [], conflicts: [{ id: e.id, server }], cursor: 9 }),
      'GET /sync': () => pull({ cursor: 9 }),
    });
    await engine().sync();
    expect((await db.get('entries', e.id)).item).toBe('Vom anderen Gerät');
    expect(await db.count('outbox')).toBe(0);
    expect(events).toContainEqual({ type: 'conflicts', count: 1 });
  });

  it('verarbeitet purged: Buchungen und Belege werden lokal endgueltig entfernt', async () => {
    const e = await repo.saveEntry(out());
    await db.tx(['outbox'], (t) => t.objectStore('outbox').clear());
    await db.put('receipts', { id: 'r-1', mime: 'image/jpeg', size: 1, version: 1 });
    await db.put('blobs', { id: 'r-1', blob: new Blob(['x']), mime: 'image/jpeg' });
    mockFetch({ 'GET /sync': () => pull({ purged: { entries: [e.id], receipts: ['r-1'] }, cursor: 11 }) });
    await engine().sync();
    expect(await db.get('entries', e.id)).toBeUndefined();
    expect(await db.get('receipts', 'r-1')).toBeUndefined();
    expect(await db.get('blobs', 'r-1')).toBeUndefined();
  });

  it('Pull ueberschreibt keine neuere, noch nicht gesendete lokale Fassung', async () => {
    const e = await repo.saveEntry(out({ item: 'Lokal neu' }));
    mockFetch({
      'POST /sync': () => { throw new TypeError('Failed to fetch'); },
    });
    await engine().sync(); // Netzfehler beim Senden -> bleibt in der Outbox
    mockFetch({
      'POST /sync': () => apiError(500, 'server_error'),
      'GET /sync': () => pull({ entries: [serverize({ ...e, item: 'Alt', updated_at: '2000-01-01T00:00:00Z' }, 5, 12)], cursor: 12 }),
    });
    await createSyncEngine({ isOnline: () => true, canWrite: () => false }).sync(); // nur Pull
    const local = await db.get('entries', e.id);
    expect(local.item).toBe('Lokal neu');
    expect(local.number).toBe(5);
    expect(await db.count('outbox')).toBe(1);
  });

  it('markiert per 422 abgelehnte Buchungen und sendet den Rest erneut', async () => {
    const bad = await repo.saveEntry(out({ item: 'Kaputt' }));
    const good = await repo.saveEntry(out({ item: 'Gut' }));
    let n = 0;
    const calls = mockFetch({
      'POST /sync': (c) => {
        n++;
        if (c.body.entries.some((x) => x.id === bad.id)) return apiError(422, 'validation_failed', { fields: { [bad.id]: 'Bezug fehlt' } });
        return { applied: c.body.entries.map((x) => serverize(x, 1, 3)), conflicts: [], cursor: 3 };
      },
      'GET /sync': () => pull({ cursor: 3 }),
    });
    await engine().sync();
    expect(n).toBe(2);
    expect(calls.filter((c) => c.method === 'POST')[1].body.entries.map((x) => x.id)).toEqual([good.id]);
    expect((await db.get('outbox', bad.id))._error).toBe('Bezug fehlt');
    expect(statuses.at(-1)).toMatchObject({ state: 'pending', pending: 1, rejected: 1 });
    // erneutes Bearbeiten hebt die Ablehnung auf
    await repo.saveEntry({ ...bad, item: 'Repariert' });
    expect((await db.get('outbox', bad.id))._error).toBeUndefined();
  });

  it('offline: kein Netzaufruf, Status offline mit Anzahl ausstehender Aenderungen', async () => {
    await repo.saveEntry(out());
    await repo.trashEntry((await repo.saveEntry(out({ item: 'B' }))).id);
    const calls = mockFetch({});
    await engine({ isOnline: () => false }).sync();
    expect(calls).toHaveLength(0);
    expect(statuses.at(-1)).toMatchObject({ state: 'offline', pending: 2 });
  });

  it('Netzfehler mitten im Lauf -> Status offline, Outbox bleibt erhalten', async () => {
    await repo.saveEntry(out());
    mockFetch({ 'POST /sync': () => { throw new TypeError('Failed to fetch'); } });
    await engine().sync();
    expect(statuses.at(-1)).toMatchObject({ state: 'offline', pending: 1 });
    expect(await db.count('outbox')).toBe(1);
  });

  it('ohne Schreibrecht (Lesen ohne PIN): nur Pull, Outbox bleibt', async () => {
    clearToken();
    await repo.saveEntry(out());
    const calls = mockFetch({ 'GET /sync': () => pull({ cursor: 1 }) });
    await engine({ canWrite: () => false }).sync();
    expect(calls.map((c) => c.method)).toEqual(['GET']);
    expect(calls[0].headers.Authorization).toBeUndefined();
    expect(await db.count('outbox')).toBe(1);
  });

  it('Papierkorb + Wiederherstellen werden als deleted_at gesendet', async () => {
    const e = await repo.saveEntry(out());
    await repo.trashEntry(e.id);
    let sent;
    mockFetch({
      'POST /sync': (c) => { sent = c.body.entries[0]; return { applied: c.body.entries.map((x) => serverize(x, 1, 2)), conflicts: [], cursor: 2 }; },
      'GET /sync': () => pull({ cursor: 2 }),
    });
    await engine().sync();
    expect(sent.deleted_at).toBeTruthy();
    await repo.restoreEntry(e.id);
    await engine().sync();
    expect(sent.deleted_at).toBeNull();
  });

  it('teilt grosse Outboxen in Pakete zu max. 500', async () => {
    for (let i = 0; i < 501; i++) await db.put('outbox', { ...repo.normalizeEntry({ ...out(), id: 'id-' + i, created_at: 'a', updated_at: 'a' }) });
    const sizes = [];
    mockFetch({
      'POST /sync': (c) => { sizes.push(c.body.entries.length); return { applied: [], conflicts: [], cursor: 1 }; },
      'GET /sync': () => pull({ cursor: 1 }),
    });
    await engine().sync();
    expect(sizes).toEqual([500, 1]);
  });

  it('single-flight: Trigger waehrend eines Laufs fuehren zu genau einem Folgelauf', async () => {
    let gets = 0;
    mockFetch({ 'GET /sync': async () => { gets++; await new Promise((r) => setTimeout(r, 10)); return pull(); } });
    const en = engine();
    const p = en.sync(); en.sync(); en.sync();
    await p;
    await vi.waitFor(() => expect(gets).toBe(2));
  });
});
