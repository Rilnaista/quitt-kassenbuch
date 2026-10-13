// Einfaches i18n: verschachtelte Schluessel in <lang>.json, {param}-Platzhalter.
// Weitere Sprachen: neue JSON-Datei daneben und in `dicts` eintragen.
import de from './de.json';
import deTools from './tools.de.json'; // Kontoauszug, Sicherung, Update, Diagnose
import deHelp from './help.de.json'; // Hilfe-Texte (offline im Bundle)
import deReceipt from './receipt.de.json'; // Beleg-Flow, Belegerkennung
import { fmtCents } from '../lib/money.js';

/** Tiefes Zusammenfuehren mehrerer Sprachdateien derselben Sprache. */
export function merge(...parts) {
  const out = {};
  for (const p of parts) {
    for (const [k, v] of Object.entries(p)) {
      out[k] = v && typeof v === 'object' && !Array.isArray(v) ? merge(out[k] || {}, v) : v;
    }
  }
  return out;
}

const dicts = { de: merge(de, deTools, deHelp, deReceipt) };
let dict = dicts.de;

export function t(key, params) {
  let v = key.split('.').reduce((o, k) => (o == null ? o : o[k]), dict);
  if (typeof v !== 'string') return key;
  if (params) v = v.replace(/\{(\w+)\}/g, (_, k) => (params[k] != null ? params[k] : ''));
  return v;
}

export const fmtEur = fmtCents;

/** ISO-Datum oder -Zeitstempel -> TT.MM.JJJJ */
export function fmtDate(iso) {
  if (!iso) return '';
  if (iso.length > 10) {
    const d = new Date(iso);
    if (!isNaN(d)) return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
  }
  const [y, m, d] = iso.slice(0, 10).split('-');
  return `${d}.${m}.${y}`;
}

/** Heutiges Datum lokal als YYYY-MM-DD (nicht UTC, sonst falscher Tag kurz nach Mitternacht). */
export function today() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Nutzertext zu einem ApiError: Server-Klartext bevorzugt, sonst eigener Text je Code. */
export function errorText(err) {
  if (!err) return t('errors.unknown');
  if (err.code === 'network') return t('errors.network');
  if (err.message && err.name === 'ApiError' && err.message !== err.code) return err.message;
  const k = 'errors.' + err.code;
  const v = t(k);
  return v === k ? t('errors.unknown') : v;
}
