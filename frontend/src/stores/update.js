// Update-Zustand (Server-Update ueber GET /update/check). Merkt "Update verfuegbar" fuer den Hinweis-Punkt.
import { defineStore } from 'pinia';
import * as api from '../api/index.js';
import { silentCheck, storedAvailable, storeAvailable, markChecked } from '../lib/updateCheck.js';

export const useUpdate = defineStore('update', {
  state: () => ({ info: null, latest: (storedAvailable() || {}).latest || null, checking: false }),
  getters: { hasHint: (s) => !!s.latest },
  actions: {
    /** Beim App-Start (angemeldet): hoechstens 1x/Tag, still. */
    async startupCheck() {
      const res = await silentCheck(() => api.updateCheck());
      if (res) { this.info = res; this.latest = res.available && !res.docker ? res.latest : null; }
    },
    /** Ausdrueckliche Pruefung (Knopf "Nach Update suchen", umgeht den Cache; wirft ApiError). */
    async check() {
      this.checking = true;
      try {
        const res = await api.updateCheck(true);
        this.info = res;
        this.latest = res.available && !res.docker ? res.latest : null;
        markChecked(Date.now());
        storeAvailable(this.latest ? res : null);
        return res;
      } finally { this.checking = false; }
    },
    clearHint() { this.latest = null; storeAvailable(null); },
  },
});
