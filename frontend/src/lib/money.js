// Betraege sind IMMER ganze Cent (int). Keine Fliesskomma-Arithmetik auf Euro-Werten.

const eur = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' });
const num = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** Cent -> "1.234,56 €" (Vorzeichen bleibt erhalten). */
export const fmtCents = (cents) => eur.format((cents || 0) / 100);
/** Cent -> "1.234,56" (fuer Eingabefelder). */
export const fmtCentsPlain = (cents) => num.format((cents || 0) / 100);

/**
 * Nutzereingabe -> Cent (int) oder null bei ungueltiger Eingabe.
 * Akzeptiert "12", "12,5", "12,50", "1.234,56", "12.50" (Punkt als Dezimaltrenner nur
 * bei genau 1-2 Nachkommastellen), optional mit "€" und Leerzeichen.
 */
export function parseCents(input) {
  if (input == null) return null;
  let s = String(input).replace(/[€\s ]/g, '');
  if (!s) return null;
  if (s.includes(',')) {
    s = s.replace(/\./g, '').replace(',', '.');
  } else if (!/^\d+\.\d{1,2}$/.test(s)) {
    s = s.replace(/\./g, '');
  }
  const m = /^(\d+)(?:\.(\d{0,2}))?$/.exec(s);
  if (!m) return null;
  const euros = parseInt(m[1], 10);
  const frac = (m[2] || '').padEnd(2, '0');
  const cents = euros * 100 + parseInt(frac || '0', 10);
  return Number.isSafeInteger(cents) ? cents : null;
}
