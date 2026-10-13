import { describe, it, expect } from 'vitest';
import { parseReceiptText, parseDate, findDates } from '../src/lib/receiptParse.js';

const EDEKA = `EDEKA Neubauer
Hauptstr. 12
12345 Musterstadt
Tel. 01234 / 56789
Datum: 12.10.2026   14:32
Bio Vollmilch 1L            1,19 A
Bananen
0,832 kg x 1,99 EUR/kg      1,66 B
Vollkornbrot 750g           2,49 B
Rabatt Vollkornbrot        -0,50
Pfand 0,25                  0,25 A
SUMME            EUR        5,09
Bar                        10,00
Rückgeld                    4,91
MwSt 7%   0,25 netto 3,50
Vielen Dank für Ihren Einkauf!`;

const DM = `dm-drogerie markt
Filiale 1234
25.09.26 18:05 Bon-Nr 4711
Zahnpasta Aronal 75ml 2,95 1
Duschgel Frische 250ml 1,75 1
Geschenkpapier 3 x 0,95 2,85 2
Zwischensumme 7,55
Gutschein 10% 0,76
Gesamtbetrag EUR 6,79
girocard 6,79`;

const BAUHAUS = `BAUHAUS
Rechnung
Datum 3/4/2026
Blumenerde 40l                      1.234,56
Pflanztopf Terrakotta  4 x 3,99      15,96 B
Zu zahlen:                        1.250,52 EUR
Karte`;

const NOISY = `REWE Markt GmbH
Dat: O1.O2.2O26
ÄPFEL ROT 1,5KG 3.49 B
|| JOGHURT NATUR 0.79 B
Leergut -0,25
SUMME 4,03
`;

describe('Datum', () => {
  it('erkennt verschiedene Formate', () => {
    expect(findDates('am 12.10.2026 um 14:32')).toEqual(['2026-10-12']);
    expect(findDates('25.09.26')).toEqual(['2025-09-26'.replace('2025-09-26', '2026-09-25')]);
    expect(findDates('3/4/2026')).toEqual(['2026-04-03']);
    expect(findDates('1-2-2026')).toEqual(['2026-02-01']);
    expect(findDates('2026-10-12')).toEqual(['2026-10-12']);
  });
  it('verwirft ungueltige Daten und Preise', () => {
    expect(findDates('31.02.2026')).toEqual([]);
    expect(findDates('Summe 12.10')).toEqual([]);
    expect(findDates('Preis 1.234,56')).toEqual([]);
    expect(findDates('13.13.2026')).toEqual([]);
  });
  it('bevorzugt Zeile mit "Datum"', () => {
    expect(parseDate('Gedruckt 01.01.2020\nDatum: 05.06.2026')).toBe('2026-06-05');
    expect(parseDate('nichts')).toBeNull();
  });
});

describe('Positionen', () => {
  it('Supermarktbon: Positionen, Menge/Gewicht, Rabatt, Summe', () => {
    const r = parseReceiptText(EDEKA);
    expect(r.date).toBe('2026-10-12');
    expect(r.total_cents).toBe(509);
    expect(r.positions).toEqual([
      { item: 'Bio Vollmilch 1L', amount_cents: 119 },
      { item: 'Bananen', amount_cents: 166 },
      { item: 'Vollkornbrot 750g', amount_cents: 249 },
      { item: 'Rabatt Vollkornbrot', amount_cents: -50 },
      { item: 'Pfand 0,25', amount_cents: 25 },
    ]);
    // Positionen ergeben die Summe
    expect(r.positions.reduce((s, p) => s + p.amount_cents, 0)).toBe(r.total_cents);
  });
  it('Drogerie: Steuerklasse am Ende, Zwischensumme ausgeschlossen, Gutschein negativ', () => {
    const r = parseReceiptText(DM);
    expect(r.date).toBe('2026-09-25');
    expect(r.total_cents).toBe(679);
    expect(r.positions.map((p) => p.amount_cents)).toEqual([295, 175, 285, -76]);
    expect(r.positions.find((p) => /Zwischen/.test(p.item))).toBeUndefined();
  });
  it('Tausenderpunkt, Menge x Preis, Zu zahlen als Summe', () => {
    const r = parseReceiptText(BAUHAUS);
    expect(r.date).toBe('2026-04-03');
    expect(r.positions).toEqual([
      { item: 'Blumenerde 40l', amount_cents: 123456 },
      { item: 'Pflanztopf Terrakotta', amount_cents: 1596 },
    ]);
    expect(r.total_cents).toBe(125052);
  });
  it('Punkt als Dezimaltrenner, Leergut negativ, Rauschen', () => {
    const r = parseReceiptText(NOISY);
    expect(r.positions).toEqual([
      { item: 'ÄPFEL ROT 1,5KG', amount_cents: 349 },
      { item: 'JOGHURT NATUR', amount_cents: 79 },
      { item: 'Leergut', amount_cents: -25 },
    ]);
    expect(r.total_cents).toBe(403);
  });
  it('Nachgestelltes Minuszeichen', () => {
    const r = parseReceiptText('Datum 01.02.2026\nSeife 2,00\nRabatt 0,30-\nSumme 1,70');
    expect(r.positions.map((p) => p.amount_cents)).toEqual([200, -30]);
  });
  it('leerer/unbrauchbarer Text', () => {
    expect(parseReceiptText('')).toEqual({ date: null, positions: [], total_cents: null });
    expect(parseReceiptText('Vielen Dank\nBis bald').positions).toEqual([]);
  });
});
