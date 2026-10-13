// Service-Worker-Registrierung + Auslieferung neuer Fassungen (Leitlinie "PWA", Punkte 3–6).
//  - Nachfragen: beim Start, beim Sichtbarwerden und alle 30 min, solange sichtbar. Fehler still.
//  - Uebernahme selbststaendig, aber nie bei ungesicherten Eingaben (dirty.js) -> sonst Hinweis.
//  - Schleifenschutz: Build-ID, fuer die zuletzt neu geladen wurde, wird gemerkt; nie zweimal.
import { isDirty, onDirtyChange } from './dirty.js';

const LAST_KEY = 'quitt.swReloadedFor';
const INTERVAL = 30 * 60 * 1000;

let reg = null;
let requested = false;
let reloading = false;
let deferredCb = () => {};

function askBuild(worker) {
  return new Promise((resolve) => {
    const ch = new MessageChannel();
    const to = setTimeout(() => resolve(null), 2000);
    ch.port1.onmessage = (ev) => { clearTimeout(to); resolve(ev.data && ev.data.build); };
    worker.postMessage({ type: 'GET_BUILD' }, [ch.port2]);
  });
}

/** Wartende Fassung uebernehmen, falls gefahrlos. force = Nutzer hat ausdruecklich getippt. */
export async function applyUpdate(force = false) {
  if (!reg || !reg.waiting) return false;
  if (!force && isDirty()) { deferredCb(true); return false; }
  const build = await askBuild(reg.waiting);
  if (!force && build && localStorage.getItem(LAST_KEY) === build) {
    // Schleifenschutz: fuer diese Fassung wurde schon neu geladen -> nur Hinweis.
    deferredCb(true);
    return false;
  }
  if (build) localStorage.setItem(LAST_KEY, build);
  requested = true;
  reg.waiting.postMessage({ type: 'SKIP_WAITING' });
  return true;
}

/** Aktiv nach neuer Fassung fragen (auch fuer "Nach Update suchen"). */
export function checkForUpdate() {
  if (!reg || document.visibilityState !== 'visible') return;
  reg.update().catch(() => { /* offline: still, naechstes Mal erneut */ });
}

function watchRegistration(r) {
  if (r.waiting && navigator.serviceWorker.controller) applyUpdate();
  r.addEventListener('updatefound', () => {
    const nw = r.installing;
    if (!nw) return;
    nw.addEventListener('statechange', () => {
      if (nw.state === 'installed' && navigator.serviceWorker.controller) applyUpdate();
    });
  });
}

/**
 * @param {object} o
 * @param {(ready:boolean)=>void} o.onDeferred  Hinweis "neue Version bereit" anzeigen
 */
export function initPwa({ onDeferred } = {}) {
  if (onDeferred) deferredCb = onDeferred;
  if (!import.meta.env.PROD || !('serviceWorker' in navigator) || !window.isSecureContext) return;
  let hadController = !!navigator.serviceWorker.controller;

  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (!hadController) { hadController = true; return; } // Erstinstallation: kein Neuladen
    if (reloading) return;
    if (!requested && isDirty()) { deferredCb(true); return; } // von anderem Tab ausgeloest
    reloading = true;
    location.reload();
  });

  navigator.serviceWorker.register('./sw.js', { scope: './' }).then((r) => {
    reg = r;
    watchRegistration(r);
    checkForUpdate();
  }).catch(() => { /* still */ });

  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') checkForUpdate(); });
  setInterval(checkForUpdate, INTERVAL);
  // Sobald keine ungesicherten Eingaben mehr da sind: verschobenes Update nachholen.
  onDirtyChange((dirty) => { if (!dirty) setTimeout(() => applyUpdate(), 300); });
  document.addEventListener('focusout', () => setTimeout(() => { if (reg && reg.waiting && !isDirty()) applyUpdate(); }, 500));
}

/**
 * Nach erfolgreichem Server-Update (POST /update/apply): neue Programmfassung holen und uebernehmen.
 * Ueber die vorhandene Mechanik (reg.update -> waiting -> applyUpdate(force)); ohne Service Worker
 * (Dev/HTTP) oder wenn nichts wartet: einfach neu laden.
 */
export async function updateNow() {
  if (reg) {
    try { await reg.update(); } catch (e) { /* offline: Neuladen unten */ }
    for (let i = 0; i < 40 && !reg.waiting; i++) {
      if (reg.installing) await new Promise((r) => setTimeout(r, 250)); else break;
    }
    if (reg.waiting && await applyUpdate(true)) return; // controllerchange laedt neu
  }
  location.reload();
}
