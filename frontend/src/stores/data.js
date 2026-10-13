// Buchungen & Belege fuer die UI. Quelle der Wahrheit ist IndexedDB; dieser Store haelt
// eine reaktive Kopie und berechnet alle abgeleiteten Werte lokal.
import { defineStore } from 'pinia';
import * as repo from '../db/repo.js';
import * as api from '../api/index.js';
import { buildLedger, balance, totals, categorySums, suggestions } from '../lib/ledger.js';
import { useSync } from './sync.js';

export const useData = defineStore('data', {
  state: () => ({ entries: [], receipts: [], pendingIds: [], rejected: {}, ready: false }),
  getters: {
    ledger: (s) => buildLedger(s.entries),
    balance: (s) => balance(s.entries),
    totals: (s) => totals(s.entries),
    categories: (s) => categorySums(s.entries),
    trashed: (s) => s.entries.filter((e) => e.deleted_at).sort((a, b) => (b.deleted_at || '').localeCompare(a.deleted_at || '')),
    reasonOptions: (s) => suggestions(s.entries, 'reason'),
    categoryOptions: (s) => suggestions(s.entries, 'category'),
    purposeOptions: (s) => suggestions(s.entries, 'purpose'),
    itemOptions: (s) => suggestions(s.entries, 'item'),
  },
  actions: {
    async load() {
      const { entries, receipts, outbox } = await repo.loadAll();
      this.entries = entries;
      this.receipts = receipts;
      this.pendingIds = outbox.map((o) => o.id);
      this.rejected = Object.fromEntries(outbox.filter((o) => o._error).map((o) => [o.id, o._error]));
      this.ready = true;
    },
    byId(id) { return this.entries.find((e) => e.id === id) || null; },
    receiptById(id) { return this.receipts.find((r) => r.id === id) || null; },
    entriesOfReceipt(rid) { return this.entries.filter((e) => e.receipt_id === rid && !e.deleted_at); },
    async changed() {
      await this.load();
      useSync().kick();
    },
    async save(entry) { const e = await repo.saveEntry(entry); await this.changed(); return e; },
    async trash(id) { await repo.trashEntry(id); await this.changed(); },
    async restore(id) { await repo.restoreEntry(id); await this.changed(); },
    async addReceipt(blob, mime) { const id = await repo.addReceipt(blob, mime); await this.load(); return id; },
    /**
     * Endgueltig loeschen (nur aus dem Papierkorb, nur online).
     * Nie synchronisierte Buchungen werden nur lokal entfernt.
     */
    async purge(id) {
      const e = this.byId(id);
      if (!e) return;
      if (e.version == null) { await repo.removeEntryLocal(id); await this.load(); return; }
      await useSync().syncNow(); // Papierkorb-Status muss beim Server angekommen sein
      try {
        await api.deleteEntry(id);
      } catch (err) {
        if (err.code !== 'not_found') throw err;
      }
      await repo.removeEntryLocal(id);
      await this.changed();
    },
    async emptyTrash() {
      const local = this.trashed.filter((e) => e.version == null);
      await useSync().syncNow();
      await api.emptyTrash();
      for (const e of local) await repo.removeEntryLocal(e.id);
      for (const e of this.trashed) await repo.removeEntryLocal(e.id);
      await this.changed();
    },
    /** Belegdatei: lokal (vorgemerkt/gecacht) oder vom Server laden und fuer offline cachen. */
    async receiptBlob(id) {
      const local = await repo.getReceiptBlob(id);
      if (local) return local;
      const blob = await api.receiptFile(id);
      await repo.cacheReceiptBlob(id, blob);
      return blob;
    },
  },
});
