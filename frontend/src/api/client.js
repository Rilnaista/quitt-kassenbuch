// Zentraler HTTP-Zugriff gegen api-contract.md.
// - Basis <app-ort>/api/v1, relativ zum eigenen Ort ermittelt (Installationspfad nie fest verdrahtet).
// - Bearer-Token, Fehlerformat { error: { code, message, fields?, retry_after? } } -> ApiError.
// - Netzfehler -> ApiError code "network" (status 0).
import { getToken, clearToken } from './token.js';

let baseOverride = null;
const authListeners = new Set();

/** Basis-URL der App (Ordner, in dem index.html liegt), mit abschliessendem "/". */
export function appBase() {
  if (baseOverride) return new URL('../../', baseOverride).href;
  return new URL('.', globalThis.location.href).href;
}

/** Basis-URL der API, mit abschliessendem "/". */
export function apiBase() {
  return baseOverride || new URL('api/v1/', appBase()).href;
}

/** Nur fuer Tests/Sonderfaelle: API-Basis explizit setzen (absolute URL, endet auf "/"). */
export function setApiBase(url) { baseOverride = url; }

/** Wird bei 401 mit gesendetem Token aufgerufen (Token verworfen) bzw. bei 403 pin_required. */
export function onAuthProblem(fn) {
  authListeners.add(fn);
  return () => authListeners.delete(fn);
}

export class ApiError extends Error {
  constructor({ status = 0, code = 'unknown', message = '', fields = null, retryAfter = null } = {}) {
    super(message || code);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.fields = fields;
    this.retryAfter = retryAfter;
  }
  get isNetwork() { return this.code === 'network'; }
}

function buildUrl(path, query) {
  const url = new URL(path.replace(/^\//, ''), apiBase());
  if (query) {
    for (const [k, v] of Object.entries(query)) if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
  }
  return url.href;
}

/**
 * Fuehrt einen API-Aufruf aus.
 * @param {string} method
 * @param {string} path      relativ zu /api/v1, z. B. "/sync"
 * @param {object} [opts]    { query, json, body (FormData/Blob), auth (default true), raw (Response zurueck), signal,
 *                             keepTokenOn401 (401 bedeutet hier "falsche Eingabe", nicht "Token ungueltig") }
 */
export async function request(method, path, opts = {}) {
  const headers = { Accept: 'application/json' };
  const token = opts.auth === false ? null : getToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  let body;
  if (opts.json !== undefined) {
    headers['Content-Type'] = 'application/json; charset=utf-8';
    body = JSON.stringify(opts.json);
  } else if (opts.body !== undefined) {
    body = opts.body; // FormData: Content-Type setzt der Browser inkl. boundary
  }
  let res;
  try {
    res = await fetch(buildUrl(path, opts.query), { method, headers, body, cache: 'no-store', credentials: 'same-origin', signal: opts.signal });
  } catch (e) {
    if (e && e.name === 'AbortError') throw e;
    throw new ApiError({ status: 0, code: 'network', message: '' });
  }
  if (res.ok) {
    if (opts.raw) return res;
    if (res.status === 204) return null;
    const ct = res.headers.get('content-type') || '';
    return ct.includes('json') ? res.json() : res.text();
  }
  let payload = null;
  try { payload = await res.json(); } catch (e) { /* kein JSON (z. B. Proxy-Fehlerseite) */ }
  const err = payload && payload.error ? payload.error : {};
  const ra = err.retry_after ?? (res.headers.get('retry-after') ? Number(res.headers.get('retry-after')) : null);
  const apiErr = new ApiError({
    status: res.status,
    code: err.code || (res.status === 401 ? 'unauthorized' : res.status >= 500 ? 'server_error' : 'http_' + res.status),
    message: err.message || '',
    fields: err.fields || null,
    retryAfter: ra,
  });
  if (res.status === 401 && token && !opts.keepTokenOn401) {
    clearToken();
    authListeners.forEach((fn) => fn(apiErr));
  } else if (apiErr.code === 'pin_required' || apiErr.code === 'not_installed') {
    authListeners.forEach((fn) => fn(apiErr));
  }
  throw apiErr;
}
