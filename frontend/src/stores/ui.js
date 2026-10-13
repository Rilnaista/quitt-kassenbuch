import { defineStore } from 'pinia';

const HTTPS_KEY = 'quitt.httpsHintDismissed';

export const useUi = defineStore('ui', {
  state: () => ({
    tab: 'home',
    fabs: { 'view-home': true, 'view-ledger': true, 'view-categories': true },
    addOpen: false,
    help: { open: false, key: 'home' },
    updateReady: false, // neue Fassung wartet, Uebernahme wegen Eingaben verschoben
    httpsHintDismissed: (() => { try { return localStorage.getItem(HTTPS_KEY) === '1'; } catch (e) { return false; } })(),
  }),
  getters: {
    // HTTPS-Hinweis nur, wenn kein sicherer Kontext (dann gehen weder Service Worker noch Installation).
    showHttpsHint: (s) => !s.httpsHintDismissed && typeof window !== 'undefined' && !window.isSecureContext,
  },
  actions: {
    openHelp(key) { this.help.key = key; this.help.open = true; },
    dismissHttpsHint() {
      this.httpsHintDismissed = true;
      try { localStorage.setItem(HTTPS_KEY, '1'); } catch (e) { /* egal */ }
    },
  },
});
