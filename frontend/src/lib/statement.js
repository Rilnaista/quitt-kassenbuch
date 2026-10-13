// Kontoauszug: Zeitraum-Voreinstellungen und lokale Kurzvorschau (Salden aus IndexedDB-Daten).
export const PRESETS = ['all', 'thisYear', 'lastMonth', 'custom'];

const p2 = (n) => String(n).padStart(2, '0');
export const ymd = (d) => `${d.getFullYear()}-${p2(d.getMonth() + 1)}-${p2(d.getDate())}`;

/**
 * Zeitraum einer Voreinstellung. `now` = Date (lokal), `earliest` = fruehestes Buchungsdatum (YYYY-MM-DD) oder null.
 * Liefert { from, to } als YYYY-MM-DD.
 */
export function presetRange(id, now, earliest = null) {
  const y = now.getFullYear();
  if (id === 'thisYear') return { from: `${y}-01-01`, to: ymd(now) };
  if (id === 'lastMonth') {
    const first = new Date(y, now.getMonth() - 1, 1);
    const last = new Date(y, now.getMonth(), 0);
    return { from: ymd(first), to: ymd(last) };
  }
  // 'all' (und Fallback fuer 'custom'): ab erster Buchung bis heute
  return { from: earliest || `${y}-01-01`, to: ymd(now) };
}

export const validRange = (from, to) => /^\d{4}-\d{2}-\d{2}$/.test(from || '') && /^\d{4}-\d{2}-\d{2}$/.test(to || '') && from <= to;

/** Kurzvorschau aus buildLedger()-Zeilen: Anfangssaldo, Endsaldo, Anzahl im Zeitraum. */
export function summarize(ledger, from, to) {
  let opening = 0;
  let closing = 0;
  let count = 0;
  for (const e of ledger) {
    if (e.date < from) { opening = e.balance_cents; closing = e.balance_cents; }
    else if (e.date <= to) { count++; closing = e.balance_cents; }
  }
  return { opening, closing, count };
}
