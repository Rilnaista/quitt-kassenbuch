// Darstellung ist GERAETE-lokal (Vertrag: nicht im Server) -> localStorage.
import { defineStore } from 'pinia';

const KEY = 'quitt.appearance';
export const ACCENTS = [
  { id: 'green', hex: '#1F8A5B' }, { id: 'blue', hex: '#1565C0' }, { id: 'purple', hex: '#6A3FB5' },
  { id: 'red', hex: '#B3261E' }, { id: 'orange', hex: '#B45309' }, { id: 'gray', hex: '#455A64' },
];
export const DARK_MODES = ['auto', 'light', 'dark'];
export const AMOUNT_SCHEMES = ['classic', 'cb', 'icons'];

function load() {
  try { return JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { return {}; }
}

export const useAppearance = defineStore('appearance', {
  state: () => ({ darkMode: 'auto', accent: ACCENTS[0].hex, amountScheme: 'classic', ...load() }),
  actions: {
    set(key, value) {
      this[key] = value;
      try { localStorage.setItem(KEY, JSON.stringify({ darkMode: this.darkMode, accent: this.accent, amountScheme: this.amountScheme })); } catch (e) { /* voll/gesperrt */ }
    },
  },
});
