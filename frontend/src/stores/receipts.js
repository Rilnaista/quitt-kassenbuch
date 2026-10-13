// Belege, die noch auf Erkennung/Buchung warten ("vorgemerkt", z. B. KI-Erkennung offline).
// Liste der Beleg-IDs liegt in IndexedDB (meta), damit sie Neustarts und Sync ueberdauert.
import { defineStore } from 'pinia';
import * as db from '../db/index.js';

const KEY = 'pendingReceipts';

export const useReceiptQueue = defineStore('receiptQueue', {
  state: () => ({ ids: [] }),
  actions: {
    // Hinweis: setMeta bekommt eine einfache Kopie, ein Vue-Proxy ist in IndexedDB nicht klonbar.
    async load() { this.ids = await db.getMeta(KEY, []); },
    async add(id) {
      await this.load();
      if (!this.ids.includes(id)) { this.ids = [...this.ids, id]; await db.setMeta(KEY, [...this.ids]); }
    },
    async remove(id) {
      await this.load();
      this.ids = this.ids.filter((x) => x !== id);
      await db.setMeta(KEY, [...this.ids]);
    },
  },
});
