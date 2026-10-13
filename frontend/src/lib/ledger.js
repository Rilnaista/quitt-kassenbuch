// Reine Funktionen fuer abgeleitete Werte (Saldo, Summen, Vorschlaege).
// Werden lokal aus IndexedDB-Daten berechnet (Konvention: nichts davon kommt vom Server).

export const signed = (e) => (e.type === 'out' ? -e.amount_cents : e.amount_cents);
export const isActive = (e) => !e.deleted_at;

/**
 * Sortierung des Kontoverlaufs: Datum, dann Belegnummer (noch nicht vergebene zuletzt),
 * dann Anlagezeitpunkt, zuletzt id (stabil auf allen Geraeten).
 */
export function compareEntries(a, b) {
  if (a.date !== b.date) return a.date < b.date ? -1 : 1;
  const na = a.number ?? Infinity;
  const nb = b.number ?? Infinity;
  if (na !== nb) return na < nb ? -1 : 1;
  const ca = a.created_at || '';
  const cb = b.created_at || '';
  if (ca !== cb) return ca < cb ? -1 : 1;
  return a.id < b.id ? -1 : a.id > b.id ? 1 : 0;
}

/** Aktive Buchungen chronologisch aufsteigend, jede mit laufendem Saldo `balance_cents`. */
export function buildLedger(entries) {
  let s = 0;
  return entries.filter(isActive).sort(compareEntries).map((e) => {
    s += signed(e);
    return { ...e, balance_cents: s };
  });
}

export function balance(entries) {
  return entries.filter(isActive).reduce((s, e) => s + signed(e), 0);
}

export function totals(entries) {
  const t = { in: 0, out: 0 };
  for (const e of entries) if (isActive(e)) t[e.type === 'out' ? 'out' : 'in'] += e.amount_cents;
  return t;
}

/** Ausgaben je Kategorie, absteigend nach Summe. */
export function categorySums(entries) {
  const m = new Map();
  for (const e of entries) {
    if (!isActive(e) || e.type !== 'out' || !e.category) continue;
    const c = m.get(e.category) || { name: e.category, sum_cents: 0, count: 0 };
    c.sum_cents += e.amount_cents;
    c.count++;
    m.set(e.category, c);
  }
  return [...m.values()].sort((a, b) => b.sum_cents - a.sum_cents || a.name.localeCompare(b.name, 'de'));
}

/**
 * Vorschlagswerte fuer Typeahead (Grund, Kategorie ...): eindeutige Werte eines Feldes,
 * haeufigste zuerst, dann alphabetisch. Papierkorb zaehlt mit (Werte bleiben bekannt).
 */
export function suggestions(entries, field) {
  const m = new Map();
  for (const e of entries) {
    const v = (e[field] || '').trim();
    if (v) m.set(v, (m.get(v) || 0) + 1);
  }
  return [...m.entries()].sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0], 'de')).map(([v]) => v);
}

/**
 * Filter/Suche fuer den Kontoverlauf. Suche ueber Bezug, Zweck, Grund, Kategorie,
 * Belegnummer, Datum (TT.MM.JJJJ) und Betrag (z. B. "12,50").
 */
export function filterLedger(rows, { q = '', type = '', category = '', reason = '' } = {}) {
  const s = q.trim().toLowerCase();
  return rows.filter((e) => {
    if (type && e.type !== type) return false;
    if (category && e.category !== category) return false;
    if (reason && e.reason !== reason) return false;
    if (!s) return true;
    const [y, m, d] = (e.date || '').split('-');
    const amount = (e.amount_cents / 100).toFixed(2).replace('.', ',');
    return [e.item, e.purpose, e.reason, e.category, e.number, `${d}.${m}.${y}`, amount].filter((x) => x != null).join(' ').toLowerCase().includes(s);
  });
}

const MONTHS_DE = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
/** "2026-10" -> "Oktober 2026" */
export function monthLabel(key) {
  const [y, m] = key.split('-');
  return `${MONTHS_DE[Number(m) - 1] || m} ${y}`;
}

/**
 * Gruppiert Zeilen (Reihenfolge bleibt erhalten, z. B. neueste oben) nach Monat des Buchungsdatums.
 * Je Gruppe: key (JJJJ-MM), label, in/out (Cent-Summen der enthaltenen Zeilen), rows.
 */
export function groupByMonth(rows) {
  const groups = [];
  let cur = null;
  for (const e of rows) {
    const key = (e.date || '').slice(0, 7);
    if (!cur || cur.key !== key) {
      cur = { key, label: monthLabel(key), in: 0, out: 0, rows: [] };
      groups.push(cur);
    }
    cur[e.type === 'out' ? 'out' : 'in'] += e.amount_cents;
    cur.rows.push(e);
  }
  return groups;
}

/** Ganzzahlige Prozentanteile, Summe genau 100 (Largest-Remainder). Summe 0 -> lauter 0. */
export function percentShares(values) {
  const total = values.reduce((s, v) => s + v, 0);
  if (total <= 0) return values.map(() => 0);
  const raw = values.map((v) => (v * 100) / total);
  const out = raw.map(Math.floor);
  let rest = 100 - out.reduce((s, v) => s + v, 0);
  raw.map((r, i) => [r - out[i], i]).sort((a, b) => b[0] - a[0] || a[1] - b[1]).forEach(([, i]) => { if (rest > 0) { out[i]++; rest--; } });
  return out;
}
