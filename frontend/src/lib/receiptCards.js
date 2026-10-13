// Logik des Beleg-Ergebnisbildschirms (rein, getestet):
//  - Positionskarten aus erkannten Positionen bauen; NEGATIVE Positionen (Rabatt/Pfand) werden standardmaessig
//    in die vorherige Position eingerechnet und sind einzeln wieder aufloesbar.
//  - Vorbelegung: Grund/Kategorie der ersten Karte gelten fuer alle folgenden, solange dort nicht manuell geaendert.
//  - Negative Betraege werden NIE gebucht (nur Karten mit Betrag > 0).
import { parseCents, fmtCentsPlain } from './money.js';

let seq = 0;
const key = () => `c${++seq}`;

export const newCard = (over = {}) => ({
  key: key(), item: '', gross: 0, discounts: [], override: null, skipped: false,
  reason: '', category: '', touched: { reason: false, category: false }, ...over,
});

/** Positionen { item, amount_cents } (auch negativ) -> Karten. Ein fuehrender Rabatt wird in die naechste Karte eingerechnet. */
export function buildCards(positions) {
  const cards = [];
  let lead = []; // negative Positionen vor der ersten positiven
  for (const p of positions || []) {
    const cents = Math.trunc(p.amount_cents || 0);
    if (!cents) continue;
    if (cents < 0) {
      if (cards.length) cards[cards.length - 1].discounts.push({ item: p.item || '', cents });
      else lead.push({ item: p.item || '', cents });
    } else {
      cards.push(newCard({ item: p.item || '', gross: cents, discounts: lead }));
      lead = [];
    }
  }
  return cards;
}

const sumDiscounts = (c) => c.discounts.reduce((s, d) => s + d.cents, 0);
/** Nettobetrag der Karte (Preis minus eingerechnete Rabatte) in Cent. */
export const netCents = (c) => c.gross + sumDiscounts(c);
/** Massgeblicher Betrag: manuell eingegebener, sonst Nettobetrag. null = ungueltige Eingabe. */
export const cardCents = (c) => (c.override != null ? parseCents(c.override) : netCents(c));
export const amountText = (c) => (c.override != null ? c.override : fmtCentsPlain(netCents(c)));
export const isBookable = (c) => !c.skipped && (cardCents(c) ?? 0) > 0;

/** Rabatt j der Karte i als eigene Karte (direkt dahinter) aufloesen. Die neue Karte bleibt negativ -> nicht buchbar. */
export function unfoldDiscount(cards, i, j) {
  const c = cards[i];
  const d = c.discounts[j];
  if (!d) return cards;
  const rest = { ...c, discounts: c.discounts.filter((_, k) => k !== j), override: null };
  const own = newCard({ item: d.item, gross: d.cents, reason: c.reason, category: c.category, touched: { reason: true, category: true } });
  return [...cards.slice(0, i), rest, own, ...cards.slice(i + 1)];
}

/** Negative Karte i wieder in die vorherige Karte einrechnen. */
export function foldCard(cards, i) {
  const c = cards[i];
  if (i < 1 || c.gross >= 0) return cards;
  const prev = { ...cards[i - 1], discounts: [...cards[i - 1].discounts, { item: c.item, cents: c.gross }], override: null };
  return [...cards.slice(0, i - 1), prev, ...cards.slice(i + 1)];
}

/**
 * Grund/Kategorie setzen. Bei der ersten Karte werden alle folgenden mitgezogen, die dort nicht manuell
 * geaendert wurden; bei jeder anderen Karte wird das Feld als "manuell" markiert.
 */
export function setField(cards, i, field, value) {
  return cards.map((c, k) => {
    if (k === i) return { ...c, [field]: value, touched: i === 0 ? c.touched : { ...c.touched, [field]: true } };
    if (i === 0 && !c.touched[field]) return { ...c, [field]: value };
    return c;
  });
}

/** Neue (leere) Karte hinten anfuegen, Grund/Kategorie von der ersten Karte uebernehmen. */
export function addCard(cards) {
  const first = cards[0];
  return [...cards, newCard({ reason: first ? first.reason : '', category: first ? first.category : '' })];
}

/** Buchbare Karten -> Ausgaben (ohne id/Zeitstempel; die vergibt repo.saveEntry). */
export function toEntries(cards, { date, receiptId }) {
  return cards.filter(isBookable).map((c) => ({
    type: 'out', date, amount_cents: cardCents(c), reason: c.reason, category: c.category, item: c.item, receipt_id: receiptId || null,
  }));
}
