// Alle Endpunkte aus api-contract.md als Funktionen. Pfade relativ zu <base>/api/v1.
// Antworten werden unveraendert (snake_case wie im Vertrag) zurueckgegeben.
import { request, appBase } from './client.js';

export { ApiError, apiBase, appBase, setApiBase, onAuthProblem } from './client.js';
export { getToken, setToken, clearToken, onTokenChange } from './token.js';

const enc = encodeURIComponent;

// --- System ---
export const health = () => request('GET', '/health', { auth: false });
export const systemInfo = () => request('GET', '/system/info', { auth: false });

// --- Auth ---
export const login = (pin) => request('POST', '/auth/login', { json: { pin }, auth: false });
export const authStatus = () => request('GET', '/auth/status');
export const logout = () => request('POST', '/auth/logout');
export const changePin = (oldPin, newPin) => request('POST', '/auth/pin', { json: { old_pin: oldPin, new_pin: newPin }, keepTokenOn401: true });
export const resetInfo = () => request('GET', '/auth/reset', { auth: false });
/** recoveryCode weglassen, wenn die Datei reset-pin auf dem Server liegt. */
export const resetPin = ({ recoveryCode, newPin }) =>
  request('POST', '/auth/reset', { json: recoveryCode ? { recovery_code: recoveryCode, new_pin: newPin } : { new_pin: newPin }, auth: false });

// --- Sync ---
export const syncPull = (since = 0) => request('GET', '/sync', { query: { since } });
/** entries ohne number/version, max. 500 je Aufruf (Vertrag). */
export const SYNC_PUSH_MAX = 500;
export const syncPush = (entries) => request('POST', '/sync', { json: { entries } });

// --- Papierkorb ---
export const deleteEntry = (id) => request('DELETE', `/entries/${enc(id)}`);
export const emptyTrash = () => request('POST', '/trash/empty');

// --- Belege ---
export function uploadReceipt(id, blob, filename = 'beleg.jpg') {
  const fd = new FormData();
  fd.append('file', blob, filename);
  return request('PUT', `/receipts/${enc(id)}`, { body: fd });
}
/** Belegdatei als Blob (mit Bearer bzw. ohne Token bei "Lesen ohne PIN"). */
export async function receiptFile(id) {
  const res = await request('GET', `/receipts/${enc(id)}/file`, { raw: true });
  return res.blob();
}
export const recognizeReceipt = (id) => request('POST', `/receipts/${enc(id)}/recognize`);

// --- Einstellungen ---
export const getSettings = () => request('GET', '/settings');
export const putSettings = (patch) => request('PUT', '/settings', { json: patch });
export const testRecognition = (payload) => request('POST', '/settings/recognition/test', { json: payload });
export const aiProviders = () => request('GET', '/settings/ai-providers');

// --- Downloads (Einmal-Ticket, 120 s) ---
/** Liefert eine absolute URL, die ohne Header im Browser/Share-Sheet oeffnet. */
export async function createDownload(target, params = {}) {
  const { url } = await request('POST', '/downloads', { json: { target, params } });
  return new URL(url.replace(/^\//, ''), appBase()).href;
}
export const statementUrl = (from, to) => createDownload('statement', { from, to });
export const backupUrl = () => createDownload('backup');
export const diagnosticsUrl = () => createDownload('diagnostics');
export const receiptUrl = (id) => createDownload('receipt', { id });

// --- Kontoauszug / Sicherung / Update / Protokoll (direkt mit Bearer) ---
export async function statementPdf(from, to) {
  const res = await request('GET', '/statement.pdf', { query: { from, to }, raw: true });
  return res.blob();
}
export async function backup() {
  const res = await request('GET', '/backup', { raw: true });
  return res.blob();
}
export function restore(file) {
  const fd = new FormData();
  fd.append('file', file, file.name || 'sicherung.zip');
  return request('POST', '/restore', { body: fd });
}
export const listBackups = () => request('GET', '/backups');
export const restoreBackup = (name) => request('POST', `/backups/${enc(name)}/restore`);
export const updateCheck = () => request('GET', '/update/check');
export const updateApply = () => request('POST', '/update/apply');
export const logs = ({ level, limit } = {}) => request('GET', '/logs', { query: { level, limit } });
export const diagnostics = () => request('GET', '/diagnostics');
export const logClient = (level, message, context = {}) => request('POST', '/logs/client', { json: { level, message, context }, auth: false });
