// Serverseitige Einstellungen (fuer alle Geraete). Letzter Stand wird fuer Offline-Anzeige gecacht.
import { defineStore } from 'pinia';
import * as api from '../api/index.js';

const KEY = 'quitt.settingsCache';
const DEFAULTS = { group_name: 'Unsere Kasse', read_without_pin: false, recognition_mode: 'local', ai_provider: null, ai_model: null, ai_base_url: null, ai_key_set: false };

function cached() {
  try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return null; }
}

export const useSettings = defineStore('settings', {
  state: () => ({ ...DEFAULTS, ...(cached() || {}), loaded: false }),
  actions: {
    apply(s) {
      Object.assign(this, s);
      this.loaded = true;
      const { loaded, ...rest } = this.$state; // eslint-disable-line no-unused-vars
      try { localStorage.setItem(KEY, JSON.stringify(rest)); } catch (e) { /* egal */ }
    },
    async load() {
      try { this.apply(await api.getSettings()); } catch (e) { /* offline/ohne Recht: Cache bleibt */ }
    },
    /** Teilweise speichern (PUT /settings). Wirft ApiError. */
    async save(patch) {
      this.apply(await api.putSettings(patch));
    },
  },
});
