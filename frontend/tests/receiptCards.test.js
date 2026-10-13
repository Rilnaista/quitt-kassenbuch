import { describe, it, expect } from 'vitest';
import { buildCards, netCents, cardCents, isBookable, setField, addCard, unfoldDiscount, foldCard, toEntries, amountText } from '../src/lib/receiptCards.js';

const raw = [
  { item: 'Brot', amount_cents: 249 },
  { item: 'Rabatt', amount_cents: -50 },
  { item: 'Milch', amount_cents: 119 },
  { item: 'Kaese', amount_cents: 300 },
];

describe('Rabatt-Einrechnung', () => {
  it('negative Position wird in die vorherige eingerechnet', () => {
    const c = buildCards(raw);
    expect(c).toHaveLength(3);
    expect(c[0].discounts).toEqual([{ item: 'Rabatt', cents: -50 }]);
    expect(netCents(c[0])).toBe(199);
    expect(amountText(c[0])).toBe('1,99');
  });
  it('fuehrender Rabatt geht in die naechste Position', () => {
    const c = buildCards([{ item: 'Gutschein', amount_cents: -100 }, { item: 'Tee', amount_cents: 500 }]);
    expect(c).toHaveLength(1);
    expect(netCents(c[0])).toBe(400);
  });
  it('nur negative Positionen -> keine Karte', () => {
    expect(buildCards([{ item: 'Rabatt', amount_cents: -50 }])).toEqual([]);
  });
  it('aufloesen: eigene Zeile, negativ, nie gebucht', () => {
    let c = unfoldDiscount(buildCards(raw), 0, 0);
    expect(c).toHaveLength(4);
    expect(netCents(c[0])).toBe(249);
    expect(c[1].gross).toBe(-50);
    expect(isBookable(c[1])).toBe(false);
    const e = toEntries(c, { date: '2026-10-12', receiptId: 'r1' });
    expect(e.map((x) => x.amount_cents)).toEqual([249, 119, 300]);
    expect(e.every((x) => x.amount_cents > 0)).toBe(true);
  });
  it('wieder einrechnen', () => {
    const c0 = buildCards(raw);
    const c = foldCard(unfoldDiscount(c0, 0, 0), 1);
    expect(c).toHaveLength(3);
    expect(netCents(c[0])).toBe(199);
  });
  it('Rabatt groesser als Preis -> nicht buchbar', () => {
    const c = buildCards([{ item: 'A', amount_cents: 100 }, { item: 'R', amount_cents: -150 }]);
    expect(isBookable(c[0])).toBe(false);
    expect(toEntries(c, { date: '2026-01-01' })).toEqual([]);
  });
  it('manueller Betrag ersetzt Netto; ungueltig -> nicht buchbar', () => {
    const c = buildCards(raw);
    c[0].override = '3,00';
    expect(cardCents(c[0])).toBe(300);
    c[0].override = 'abc';
    expect(isBookable(c[0])).toBe(false);
    c[0].override = '-2,00';
    expect(isBookable(c[0])).toBe(false);
  });
});

describe('Vorbelegung Grund/Kategorie', () => {
  it('erste Karte zieht die folgenden mit', () => {
    let c = buildCards(raw);
    c = setField(c, 0, 'reason', 'Geburtstag');
    c = setField(c, 0, 'category', 'Anna');
    expect(c.map((x) => x.reason)).toEqual(['Geburtstag', 'Geburtstag', 'Geburtstag']);
    expect(c.map((x) => x.category)).toEqual(['Anna', 'Anna', 'Anna']);
  });
  it('manuell geaenderte Felder bleiben unberuehrt', () => {
    let c = buildCards(raw);
    c = setField(c, 0, 'reason', 'Geburtstag');
    c = setField(c, 1, 'reason', 'Ausflug');
    c = setField(c, 0, 'reason', 'Fest');
    expect(c.map((x) => x.reason)).toEqual(['Fest', 'Ausflug', 'Fest']);
    // Kategorie unabhaengig davon
    c = setField(c, 0, 'category', 'Max');
    expect(c.map((x) => x.category)).toEqual(['Max', 'Max', 'Max']);
  });
  it('neue Position uebernimmt Vorbelegung und folgt weiter der ersten', () => {
    let c = buildCards(raw);
    c = setField(c, 0, 'reason', 'Fest');
    c = addCard(c);
    expect(c[3].reason).toBe('Fest');
    c = setField(c, 0, 'reason', 'Feier');
    expect(c[3].reason).toBe('Feier');
  });
  it('aufgeloeste Rabattkarte bekommt Vorbelegung der Quelle', () => {
    let c = buildCards(raw);
    c = setField(c, 0, 'reason', 'Fest');
    c = unfoldDiscount(c, 0, 0);
    expect(c[1].reason).toBe('Fest');
  });
});

describe('toEntries', () => {
  it('ueberspringt uebersprungene, liefert gemeinsamen receipt_id', () => {
    let c = buildCards(raw);
    c[1].skipped = true;
    const e = toEntries(c, { date: '2026-10-12', receiptId: 'rid' });
    expect(e).toHaveLength(2);
    expect(e.every((x) => x.receipt_id === 'rid' && x.type === 'out' && x.date === '2026-10-12')).toBe(true);
  });
});
