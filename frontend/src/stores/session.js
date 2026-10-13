// Zugriffszustand der App.
//   mode: 'loading' | 'locked' | 'readonly' | 'unlocked' | 'not_installed'
// Modell: Das Geraet meldet sich einmal per PIN an und behaelt das Token (gleitend 365 Tage).
// "Gesperrt" = kein gueltiges Token. "Jetzt sperren" = Abmelden (Token serverseitig ungueltig).
// Ist "Lesen ohne PIN" aktiv, startet die App ohne Token lesend; das Schloss oeffnet die PIN-Sperre.
import { defineStore } from 'pinia';
import * as api from '../api/index.js';
import { useSettings } from './settings.js';

const INFO_KEY = 'quitt.systemInfo';

function cachedInfo() {
  try { return JSON.parse(localStorage.getItem(INFO_KEY) || 'null'); } catch (e) { return null; }
}

export const useSession = defineStore('session', {
  state: () => ({
    mode: 'loading',
    lockOpen: false, // PIN-Sperre ueber der lesenden App eingeblendet
    info: cachedInfo(), // GET /system/info
    hasToken: !!api.getToken(),
  }),
  getters: {
    canEdit: (s) => s.mode === 'unlocked',
    readonly: (s) => s.mode === 'readonly',
    showLock: (s) => s.mode === 'locked' || (s.mode === 'readonly' && s.lockOpen),
    readWithoutPin: (s) => !!(s.info && s.info.read_without_pin),
    version: (s) => (s.info && s.info.version) || '',
  },
  actions: {
    setInfo(info) {
      this.info = info;
      try { localStorage.setItem(INFO_KEY, JSON.stringify(info)); } catch (e) { /* egal */ }
      if (info && info.group_name) {
        const st = useSettings();
        if (!st.loaded) st.group_name = info.group_name;
        st.read_without_pin = !!info.read_without_pin;
      }
    },
    decide() {
      this.hasToken = !!api.getToken();
      if (this.mode === 'not_installed') return;
      this.mode = this.hasToken ? 'unlocked' : this.readWithoutPin ? 'readonly' : 'locked';
      if (this.mode !== 'readonly') this.lockOpen = false;
    },
    async init() {
      try {
        const info = await api.systemInfo();
        if (info && info.installed === false) { this.setInfo(info); this.mode = 'not_installed'; return; }
        this.setInfo(info);
      } catch (e) {
        if (e.code === 'not_installed') { this.mode = 'not_installed'; return; }
        // offline: gecachte Info verwenden
      }
      if (api.getToken()) {
        try {
          const st = await api.authStatus();
          if (st && st.authenticated === false) api.clearToken();
          if (st && typeof st.read_without_pin === 'boolean') this.setInfo({ ...(this.info || {}), read_without_pin: st.read_without_pin });
        } catch (e) { /* offline -> Token behalten; 401 raeumt der Client selbst auf */ }
      }
      this.mode = 'loading';
      this.decide();
    },
    async retryInstalled() {
      this.mode = 'loading';
      await this.init();
    },
    /** PIN pruefen (POST /auth/login). Wirft ApiError (unauthorized, rate_limited, network). */
    async login(pin) {
      const res = await api.login(pin);
      api.setToken(res.token, res.expires_at);
      this.decide();
      this.lockOpen = false;
    },
    /** Nach PIN-Reset: neues Token uebernehmen. */
    adoptToken(token, expiresAt) {
      api.setToken(token, expiresAt);
      this.decide();
      this.lockOpen = false;
    },
    async lock() {
      try { await api.logout(); } catch (e) { /* offline: Token trotzdem lokal verwerfen */ }
      api.clearToken();
      this.decide();
    },
    openLock() { if (this.mode === 'readonly') this.lockOpen = true; },
    cancelLock() { this.lockOpen = false; },
    /** Reaktion auf 401 / pin_required / not_installed aus dem API-Client. */
    handleAuthProblem(err) {
      if (err.code === 'not_installed') { this.mode = 'not_installed'; return; }
      this.decide();
      if (err.code === 'pin_required' && this.mode === 'readonly') this.lockOpen = true;
    },
    setReadWithoutPin(v) {
      this.setInfo({ ...(this.info || {}), read_without_pin: v });
    },
  },
});
