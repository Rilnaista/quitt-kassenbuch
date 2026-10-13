// Token-Speicher (geraete-lokal). Token gilt gleitend 365 Tage (Vertrag: Auth).
const KEY = 'quitt.token';
const listeners = new Set();

function storage() {
  try { return globalThis.localStorage || null; } catch (e) { return null; }
}

export function getToken() {
  const s = storage();
  if (!s) return null;
  try { return JSON.parse(s.getItem(KEY) || 'null')?.token || null; } catch (e) { return null; }
}

export function setToken(token, expiresAt = null) {
  const s = storage();
  if (s) s.setItem(KEY, JSON.stringify({ token, expires_at: expiresAt }));
  listeners.forEach((fn) => fn(token));
}

export function clearToken() {
  const s = storage();
  if (s) s.removeItem(KEY);
  listeners.forEach((fn) => fn(null));
}

export function onTokenChange(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}
