// Stille Update-Pruefung beim App-Start: hoechstens 1x pro Tag, Fehler bleiben unsichtbar.
const LAST_KEY = 'quitt.updateLastCheck';
const AVAIL_KEY = 'quitt.updateAvailable';
export const DAY_MS = 24 * 60 * 60 * 1000;

export function shouldCheck(last, now, interval = DAY_MS) {
  if (!last || !Number.isFinite(last)) return true;
  if (last > now) return true; // Uhr zurueckgestellt
  return now - last >= interval;
}

export const lastCheck = (storage = globalThis.localStorage) => Number(storage.getItem(LAST_KEY)) || 0;
export const markChecked = (now, storage = globalThis.localStorage) => storage.setItem(LAST_KEY, String(now));

export function storedAvailable(storage = globalThis.localStorage) {
  try { return JSON.parse(storage.getItem(AVAIL_KEY) || 'null'); } catch (e) { return null; }
}
export function storeAvailable(info, storage = globalThis.localStorage) {
  if (info) storage.setItem(AVAIL_KEY, JSON.stringify({ latest: info.latest })); else storage.removeItem(AVAIL_KEY);
}

/** Fuehrt fetchCheck() nur aus, wenn faellig. Liefert Ergebnis oder null (nicht faellig/Fehler). */
export async function silentCheck(fetchCheck, { now = Date.now(), storage = globalThis.localStorage } = {}) {
  if (!shouldCheck(lastCheck(storage), now)) return null;
  try {
    const res = await fetchCheck();
    markChecked(now, storage);
    storeAvailable(res && res.available && !res.docker ? res : null, storage);
    return res;
  } catch (e) {
    return null;
  }
}
