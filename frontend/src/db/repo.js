// Lokale Schreiboperationen. Jede Aenderung landet atomar in `entries` UND `outbox`
// (updated_at = jetzt, Last-Write-Wins laut Vertrag). Der Aufrufer stoesst danach den Sync an.
import * as db from './index.js';
import { uuid } from '../lib/uuid.js';

// Streng aufsteigend (mind. 1 ms Abstand): Buchungen, die im selben Moment entstehen (z. B. mehrere Positionen
// eines Belegs), behalten so ihre Reihenfolge; der Server vergibt Belegnummern nach created_at.
let lastTs = 0;
const now = () => { lastTs = Math.max(Date.now(), lastTs + 1); return new Date(lastTs).toISOString(); };

/** Normalisiert eine Buchung auf die Vertragsfelder je Typ (in: purpose; out: reason/category/item/receipt_id). */
export function normalizeEntry(e) {
  const base = {
    id: e.id,
    type: e.type,
    date: e.date,
    amount_cents: e.amount_cents,
    purpose: null,
    reason: null,
    category: null,
    item: null,
    receipt_id: null,
    created_at: e.created_at,
    updated_at: e.updated_at,
    deleted_at: e.deleted_at ?? null,
    number: e.number ?? null,
    version: e.version ?? null,
  };
  const s = (v) => (v == null ? null : String(v).trim() || null);
  if (e.type === 'in') base.purpose = s(e.purpose);
  else {
    base.reason = s(e.reason);
    base.category = s(e.category);
    base.item = s(e.item);
    base.receipt_id = e.receipt_id || null;
  }
  return base;
}

/** Lokale Pflichtfeldpruefung (spiegelt die Server-Validierung). Liefert { feld: i18n-Schluessel }. */
export function validateEntry(e) {
  const err = {};
  if (!/^\d{4}-\d{2}-\d{2}$/.test(e.date || '')) err.date = 'form.errDate';
  if (!Number.isInteger(e.amount_cents) || e.amount_cents <= 0) err.amount_cents = 'form.errAmount';
  if (e.type === 'in') {
    if (!(e.purpose || '').trim()) err.purpose = 'form.errRequired';
  } else if (e.type === 'out') {
    for (const f of ['reason', 'category', 'item']) if (!(e[f] || '').trim()) err[f] = 'form.errRequired';
  } else err.type = 'form.errRequired';
  return err;
}

async function writeEntry(entry) {
  await db.tx(['entries', 'outbox'], async (t) => {
    await t.objectStore('entries').put(entry);
    const { number, version, ...out } = entry; // eslint-disable-line no-unused-vars
    await t.objectStore('outbox').put(out); // ersetzt evtl. aelteren, ggf. abgelehnten Stand
  });
  return entry;
}

/** Neue oder geaenderte Buchung speichern. */
export async function saveEntry(input) {
  const ts = now();
  const existing = input.id ? await db.get('entries', input.id) : null;
  const entry = normalizeEntry({
    ...(existing || {}),
    ...input,
    id: input.id || uuid(),
    created_at: existing?.created_at || ts,
    updated_at: ts,
  });
  return writeEntry(entry);
}

async function patchEntry(id, patch) {
  const cur = await db.get('entries', id);
  if (!cur) return null;
  return writeEntry({ ...cur, ...patch, updated_at: now() });
}

export const trashEntry = (id) => patchEntry(id, { deleted_at: now() });
export const restoreEntry = (id) => patchEntry(id, { deleted_at: null });

/** Lokal endgueltig entfernen (nach erfolgreichem DELETE bzw. wenn nie synchronisiert). */
export async function removeEntryLocal(id) {
  await db.tx(['entries', 'outbox'], async (t) => {
    await t.objectStore('entries').delete(id);
    await t.objectStore('outbox').delete(id);
  });
}

/** Beleg lokal vormerken: Metadaten + Datei + Upload-Auftrag. Liefert die neue Beleg-id. */
export async function addReceipt(blob, mime = blob.type || 'image/jpeg') {
  const id = uuid();
  const ts = now();
  const write = (blobRec) => db.tx(['receipts', 'blobs', 'uploads'], async (t) => {
    await t.objectStore('receipts').put({ id, mime, size: blob.size, created_at: ts, updated_at: ts, deleted_at: null, version: null });
    await t.objectStore('blobs').put(blobRec);
    await t.objectStore('uploads').put({ id, mime, created_at: ts });
  });
  try {
    await write({ id, blob, mime });
  } catch (e) {
    // Safari/Homescreen: Blob nicht speicherbar -> ArrayBuffer + mime (db.get setzt den Blob wieder zusammen)
    await write({ id, buf: await blob.arrayBuffer(), mime });
  }
  return id;
}

export async function getReceiptBlob(id) {
  const r = await db.get('blobs', id);
  return r ? r.blob : null;
}

export async function cacheReceiptBlob(id, blob) {
  await db.putBlobRecord(id, blob, blob.type);
}

export async function loadAll() {
  const [entries, receipts, outbox, uploads] = await Promise.all([db.getAll('entries'), db.getAll('receipts'), db.getAll('outbox'), db.getAll('uploads')]);
  return { entries, receipts, outbox, uploads };
}
