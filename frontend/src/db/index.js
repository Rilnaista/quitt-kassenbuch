// IndexedDB = Quelle der Wahrheit fuer die UI (offline). Schema-Aenderungen nur ueber
// neue DB_VERSION + Upgrade-Schritt in upgrade() (nie bestehende Stores umbauen ohne Migration).
//
// Stores:
//   entries   keyPath id   Entry wie im Vertrag (snake_case)
//   receipts  keyPath id   Receipt-Metadaten wie im Vertrag; lokal neu angelegt: version = null
//   blobs     keyPath id   { id, blob, mime } – Belegdateien (lokal vorgemerkt oder heruntergeladen)
//   outbox    keyPath id   Entry-Snapshot, der noch zum Server muss (+ _error bei Ablehnung)
//   uploads   keyPath id   { id, mime, created_at, _error? } – Beleg-Upload ausstehend (Datei in blobs)
//   meta      keyPath key  { key, value } – z. B. cursor
import { openDB } from 'idb';
import { toRaw } from 'vue';

export const DB_NAME = 'quitt';
export const DB_VERSION = 1;

function upgrade(db, oldVersion) {
  if (oldVersion < 1) {
    db.createObjectStore('entries', { keyPath: 'id' });
    db.createObjectStore('receipts', { keyPath: 'id' });
    db.createObjectStore('blobs', { keyPath: 'id' });
    db.createObjectStore('outbox', { keyPath: 'id' });
    db.createObjectStore('uploads', { keyPath: 'id' });
    db.createObjectStore('meta', { keyPath: 'key' });
  }
}

let dbPromise = null;
export function getDb() {
  if (!dbPromise) dbPromise = openDB(DB_NAME, DB_VERSION, { upgrade });
  return dbPromise;
}

/** Nur fuer Tests: Verbindung schliessen und DB loeschen. */
export async function _resetDb() {
  if (dbPromise) (await dbPromise).close();
  dbPromise = null;
  await new Promise((resolve) => {
    const r = indexedDB.deleteDatabase(DB_NAME);
    r.onsuccess = r.onerror = r.onblocked = () => resolve();
  });
}

const STORES = ['entries', 'receipts', 'blobs', 'outbox', 'uploads', 'meta'];

/**
 * Macht einen Wert IndexedDB-tauglich (structured clone): Vue-Proxys werden aufgeloest (toRaw), Funktionen/Symbole/
 * undefined verworfen, Arrays/einfache Objekte tief kopiert. Blob/File/ArrayBuffer/Date bleiben unveraendert.
 * Ursache des iOS-Haengers: ein Pinia-Proxy in setMeta -> DataCloneError "The object can not be cloned.".
 */
export function clean(v, seen = new WeakMap()) {
  v = toRaw(v);
  if (v === null || typeof v !== 'object') return typeof v === 'function' || typeof v === 'symbol' ? undefined : v;
  if (typeof Blob !== 'undefined' && v instanceof Blob) return v;
  if (v instanceof ArrayBuffer || ArrayBuffer.isView(v) || v instanceof Date) return v;
  if (seen.has(v)) return seen.get(v);
  if (Array.isArray(v)) {
    const out = [];
    seen.set(v, out);
    v.forEach((x) => { const c = clean(x, seen); out.push(c === undefined ? null : c); });
    return out;
  }
  const out = {};
  seen.set(v, out);
  for (const k of Object.keys(v)) {
    const c = clean(v[k], seen);
    if (c !== undefined) out[k] = c;
  }
  return out;
}

/** Ablage fuer Belegdateien: erst als Blob; scheitert das (Safari/Homescreen), als ArrayBuffer + mime. */
export async function putBlobRecord(id, blob, mime = blob.type) {
  const db = await getDb();
  try {
    return await db.put('blobs', { id, blob, mime });
  } catch (e) {
    const buf = await blob.arrayBuffer();
    return db.put('blobs', { id, buf, mime });
  }
}

export async function getAll(store) { return (await getDb()).getAll(store); }
export async function get(store, key) {
  const r = await (await getDb()).get(store, key);
  if (store === 'blobs' && r && !r.blob && r.buf) return { id: r.id, mime: r.mime, blob: new Blob([r.buf], { type: r.mime }) };
  return r;
}
export async function put(store, value) { return (await getDb()).put(store, clean(value)); }
export async function del(store, key) { return (await getDb()).delete(store, key); }
export async function count(store) { return (await getDb()).count(store); }

export async function getMeta(key, fallback = null) {
  const r = await get('meta', key);
  return r ? r.value : fallback;
}
export async function setMeta(key, value) { return put('meta', { key, value }); }

/** Mehrere Stores atomar schreiben: fn(tx) bekommt die Transaktion. */
export async function tx(stores, fn) {
  const db = await getDb();
  const raw = db.transaction(stores, 'readwrite');
  // objectStore(..).put bereinigt jeden Wert (siehe clean)
  const t = new Proxy(raw, {
    get(target, prop) {
      if (prop === 'objectStore') {
        return (n) => new Proxy(target.objectStore(n), {
          get(st, p2) { return p2 === 'put' ? (v, k) => st.put(clean(v), k) : (typeof st[p2] === 'function' ? st[p2].bind(st) : st[p2]); },
        });
      }
      const v = target[prop];
      return typeof v === 'function' ? v.bind(target) : v;
    },
  });
  const result = await fn(t);
  await t.done;
  return result;
}

/** Komplette lokale Daten verwerfen (z. B. nach Wiederherstellen einer Sicherung). */
export async function clearAll() {
  await tx(STORES, (t) => Promise.all(STORES.map((s) => t.objectStore(s).clear())));
}
