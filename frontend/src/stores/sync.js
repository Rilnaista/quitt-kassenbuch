// Verbindet die Sync-Engine mit der UI.
// Trigger: App-Start, online-Event, Sichtbarwerden, nach jeder lokalen Aenderung (entprellt).
import { defineStore } from 'pinia';
import { f7 } from 'framework7-vue';
import { createSyncEngine } from '../sync/engine.js';
import { useData } from './data.js';
import { useSession } from './session.js';
import { t } from '../i18n/index.js';

let engine = null;
let kickTimer = null;

export const useSync = defineStore('sync', {
  state: () => ({ state: 'idle', pending: 0, rejected: 0, lastSync: null, online: typeof navigator === 'undefined' ? true : navigator.onLine !== false, conflicts: 0, quietReset: false }),
  getters: {
    // Anzeige laut Brief: offline / X Aenderungen ausstehend / synchron
    display: (s) => {
      if (!s.online || s.state === 'offline') return 'offline';
      if (s.state === 'syncing') return 'syncing';
      if (s.state === 'error') return 'error';
      return s.pending ? 'pending' : 'synced';
    },
  },
  actions: {
    init() {
      if (engine) return;
      const session = useSession();
      engine = createSyncEngine({
        isOnline: () => navigator.onLine !== false,
        canWrite: () => session.canEdit,
        onChange: () => useData().load(),
        onStatus: (st) => { this.state = st.state; this.pending = st.pending; this.rejected = st.rejected; this.lastSync = st.lastSync; },
        onEvent: (ev) => {
          if (ev.type === 'conflicts') this.conflicts += ev.count;
          if (ev.type === 'data_reset' && !this.quietReset) { try { f7.toast.create({ text: t('sync.dataReset'), closeTimeout: 4000, position: 'center' }).open(); } catch (e) { /* UI noch nicht bereit */ } }
        },
      });
      window.addEventListener('online', () => { this.online = true; this.syncNow(); });
      window.addEventListener('offline', () => { this.online = false; engine.refreshStatus('offline'); });
      document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') this.syncNow(); });
      engine.refreshStatus();
      this.syncNow();
    },
    syncNow() {
      if (!engine || useSession().mode === 'not_installed' || useSession().mode === 'locked') return Promise.resolve();
      return engine.sync();
    },
    /** Manuell: lokalen Datenstand verwerfen und neu laden (kein Server-Reset-Hinweis, die Seite zeigt eigenen Toast). */
    async resetLocal() {
      if (!engine) return;
      this.quietReset = true;
      try { await engine.resetAndSync(); } finally { this.quietReset = false; }
    },
    /** Nach jeder Aenderung: kurz entprellt, damit Serien (z. B. mehrere Positionen) gebuendelt gehen. */
    kick() {
      clearTimeout(kickTimer);
      if (engine) engine.refreshStatus();
      kickTimer = setTimeout(() => this.syncNow(), 300);
    },
  },
});
