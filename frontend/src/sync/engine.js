// Sync-Engine: Outbox -> Server, dann Server -> lokal (since-Cursor).
//
// Ablauf je Lauf (single-flight; Trigger waehrend eines Laufs -> genau ein Folgelauf):
//  1. Beleg-Uploads (PUT /receipts/{id}) – MUESSEN vor den Entries laufen, sonst 422 (receipt_id unbekannt).
//  2. Entries aus der Outbox (POST /sync, max 500 je Aufruf). Entries, deren Beleg noch nicht
//     hochgeladen ist, warten. applied -> lokal uebernehmen; conflicts -> Server-Stand uebernehmen (LWW).
//     Outbox-Eintrag nur entfernen, wenn er waehrend des Sendens nicht erneut geaendert wurde.
//     422 mit fields je id -> diese Entries als abgelehnt markieren (_error), Rest erneut senden.
//  3. GET /sync?since=<cursor>: Server-Stand uebernehmen, ausser lokal liegt eine neuere,
//     noch nicht gesendete Fassung in der Outbox. purged -> lokal endgueltig entfernen.
//     Cursor wird NUR aus GET /sync uebernommen (der POST-Cursor koennte fremde Aenderungen ueberspringen).
//
// Datenbestand-Kennung (data_id, meta): Weicht sie vom Server ab (GET/POST-Antwort) oder antwortet POST mit 409
// data_reset, wurde der Server-Datenbestand ersetzt -> lokale Daten (entries, receipts, blobs, outbox, uploads, Cursor)
// verwerfen und ab since=0 neu laden. Token und Geraete-Einstellungen liegen in localStorage und bleiben unberuehrt.
// Erster Abgleich ohne gemerkte data_id: Kennung einfach uebernehmen.
//
// Schreiben ohne Token ist nicht moeglich (pin_required) -> dann nur lesen, Outbox bleibt erhalten.
import * as db from '../db/index.js';
import * as api from '../api/index.js';

const LOCAL_FIELDS = ['_error'];
const strip = (e) => {
  const o = { ...e };
  delete o.number;
  delete o.version;
  delete o.balance_cents;
  LOCAL_FIELDS.forEach((k) => delete o[k]);
  return o;
};
const newer = (a, b) => (a || '') > (b || '');

export function createSyncEngine({ isOnline = () => globalThis.navigator?.onLine !== false, canWrite = () => !!api.getToken(), onChange = () => {}, onStatus = () => {}, onEvent = () => {} } = {}) {
  let running = null;
  let again = false;

  const status = { state: 'idle', pending: 0, rejected: 0, lastSync: null, error: null };
  const emit = (patch) => { Object.assign(status, patch); onStatus({ ...status }); };

  async function pendingCounts() {
    const [outbox, uploads] = await Promise.all([db.getAll('outbox'), db.getAll('uploads')]);
    const rejected = outbox.filter((o) => o._error).length + uploads.filter((u) => u._error).length;
    return { pending: outbox.length + uploads.length, rejected };
  }

  async function refreshStatus(state) {
    const c = await pendingCounts();
    const s = state || (!isOnline() ? 'offline' : c.pending ? 'pending' : 'synced');
    emit({ ...c, state: s });
  }

  async function uploadReceipts() {
    const uploads = (await db.getAll('uploads')).filter((u) => !u._error);
    for (const u of uploads) {
      const file = await db.get('blobs', u.id);
      if (!file) { await db.del('uploads', u.id); continue; }
      const ext = (u.mime || file.mime || 'image/jpeg').split('/')[1].replace('jpeg', 'jpg');
      try {
        const rec = await api.uploadReceipt(u.id, file.blob, `beleg-${u.id}.${ext}`);
        await db.tx(['receipts', 'uploads'], async (t) => {
          await t.objectStore('receipts').put(rec);
          await t.objectStore('uploads').delete(u.id);
        });
      } catch (e) {
        if (e.status === 422 || e.status === 413 || e.status === 415) {
          await db.put('uploads', { ...u, _error: e.message || e.code });
          onEvent({ type: 'rejected', kind: 'receipt', id: u.id, message: e.message });
        } else throw e;
      }
    }
  }

  async function applyServerEntry(t, server) {
    await t.objectStore('entries').put(server);
  }

  /** Lokalen Datenstand verwerfen (Token/Einstellungen liegen in localStorage, nicht hier). newId = neue Kennung oder null. */
  async function discardLocal(newId) {
    const c = await pendingCounts();
    if (c.pending) console.warn(`[sync] Datenbestand neu: ${c.pending} ungesendete Änderung(en) verworfen`);
    await db.tx(['entries', 'receipts', 'blobs', 'outbox', 'uploads', 'meta'], async (t) => {
      await Promise.all(['entries', 'receipts', 'blobs', 'outbox', 'uploads', 'meta'].map((s) => t.objectStore(s).clear()));
      if (newId) await t.objectStore('meta').put({ key: 'data_id', value: newId });
    });
    onEvent({ type: 'data_reset', discarded: c.pending });
  }

  /** Antwort-Kennung pruefen: abweichend -> Fehler data_reset (Abbruch), erstmals -> merken. */
  async function checkDataId(id) {
    if (!id) return;
    const stored = await db.getMeta('data_id', null);
    if (!stored) {
      // Altgeraet (vor REQ-073 schon synchronisiert): Kennung erst beim Pull festlegen (dort Vollabgleich)
      if ((await db.getMeta('cursor', 0)) === 0) await db.setMeta('data_id', id);
    } else if (stored !== id) throw Object.assign(new Error('data_reset'), { code: 'data_reset', status: 409, dataId: id });
  }

  async function pushEntries() {
    const pendingUploads = new Set((await db.getAll('uploads')).map((u) => u.id));
    let queue = (await db.getAll('outbox')).filter((o) => !o._error && !(o.receipt_id && pendingUploads.has(o.receipt_id)));
    while (queue.length) {
      const batch = queue.slice(0, api.SYNC_PUSH_MAX);
      let res;
      try {
        res = await api.syncPush(batch.map(strip), await db.getMeta('data_id', null));
        await checkDataId(res && res.data_id);
      } catch (e) {
        if (e.status === 422 && e.fields && typeof e.fields === 'object') {
          const bad = new Set(Object.keys(e.fields).filter((id) => batch.some((b) => b.id === id)));
          if (!bad.size) throw e; // nicht zuordenbar -> naechster Lauf
          for (const id of bad) {
            const cur = await db.get('outbox', id);
            if (cur) await db.put('outbox', { ...cur, _error: e.fields[id] });
            onEvent({ type: 'rejected', kind: 'entry', id, message: e.fields[id] });
          }
          queue = queue.filter((q) => !bad.has(q.id));
          continue;
        }
        throw e;
      }
      const sent = new Map(batch.map((b) => [b.id, b]));
      await db.tx(['entries', 'outbox'], async (t) => {
        const ob = t.objectStore('outbox');
        for (const e of res.applied || []) {
          const cur = await ob.get(e.id);
          const changedMeanwhile = cur && cur.updated_at !== sent.get(e.id)?.updated_at;
          if (changedMeanwhile) {
            // lokale Folgeaenderung behalten, aber Serverfelder (number/version) mitnehmen
            await t.objectStore('entries').put({ ...cur, number: e.number, version: e.version });
          } else {
            await applyServerEntry(t, e);
            if (cur) await ob.delete(e.id);
          }
        }
        for (const c of res.conflicts || []) {
          const cur = await ob.get(c.id);
          if (cur && cur.updated_at !== sent.get(c.id)?.updated_at && newer(cur.updated_at, c.server.updated_at)) continue;
          await applyServerEntry(t, c.server);
          if (cur) await ob.delete(c.id);
        }
      });
      if ((res.conflicts || []).length) onEvent({ type: 'conflicts', count: res.conflicts.length });
      queue = queue.slice(batch.length);
    }
  }

  async function pull() {
    const since = await db.getMeta('cursor', 0);
    let res = await api.syncPull(since);
    const stored = await db.getMeta('data_id', null);
    // Abweichende Kennung ODER Altgeraet ohne Kennung mit bisherigem Abgleich -> einmal komplett neu laden
    if (res.data_id && (stored ? res.data_id !== stored : since > 0)) {
      await discardLocal(res.data_id);
      res = since === 0 ? res : await api.syncPull(0);
    }
    await db.tx(['entries', 'receipts', 'blobs', 'outbox', 'uploads', 'meta'], async (t) => {
      const ob = t.objectStore('outbox');
      for (const e of res.entries || []) {
        const local = await ob.get(e.id);
        if (local && newer(local.updated_at, e.updated_at)) {
          // lokal neuer und noch nicht gesendet: lokale Fassung bleibt, Servernummer uebernehmen
          await t.objectStore('entries').put({ ...local, number: e.number, version: e.version });
          continue;
        }
        await t.objectStore('entries').put(e);
        if (local) await ob.delete(e.id);
      }
      for (const r of res.receipts || []) await t.objectStore('receipts').put(r);
      const purged = res.purged || {};
      for (const id of purged.entries || []) {
        await t.objectStore('entries').delete(id);
        await ob.delete(id);
      }
      for (const id of purged.receipts || []) {
        await t.objectStore('receipts').delete(id);
        await t.objectStore('blobs').delete(id);
        await t.objectStore('uploads').delete(id);
      }
      if (typeof res.cursor === 'number') await t.objectStore('meta').put({ key: 'cursor', value: res.cursor });
      if (res.data_id) await t.objectStore('meta').put({ key: 'data_id', value: res.data_id });
    });
  }

  async function runOnce() {
    if (!isOnline()) { await refreshStatus('offline'); return; }
    emit({ state: 'syncing', error: null });
    try {
      if (canWrite()) {
        try {
          await uploadReceipts();
          await pushEntries();
        } catch (e) {
          if (e && e.code === 'data_reset') await discardLocal(e.dataId || null);
          else throw e;
        }
      }
      await pull();
      emit({ lastSync: new Date().toISOString() });
      onChange();
      await refreshStatus();
    } catch (e) {
      onChange();
      if (e && e.code === 'network') await refreshStatus('offline');
      else {
        const c = await pendingCounts();
        emit({ ...c, state: 'error', error: e });
      }
    }
  }

  /** Startet einen Sync-Lauf; laeuft schon einer, folgt genau ein weiterer. */
  function sync() {
    if (running) { again = true; return running; }
    running = (async () => {
      do { again = false; await runOnce(); } while (again);
    })().finally(() => { running = null; });
    return running;
  }

  /** Manuelles "Neu synchronisieren": lokalen Datenstand verwerfen und komplett neu laden (wartet auf laufenden Lauf). */
  async function resetAndSync() {
    if (running) await running;
    await discardLocal(null);
    onChange();
    await sync();
  }

  return { sync, resetAndSync, refreshStatus, get status() { return { ...status }; } };
}
