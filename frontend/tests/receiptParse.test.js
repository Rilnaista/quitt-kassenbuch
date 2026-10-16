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

describe('Parser: OCR-Toleranz (generische Regeln)', () => {
  const P = (t) => parseReceiptText(t);

  it('Name in der Zeile davor, Mengenzeile mit beliebiger Einheit ("2 Kst x 8,99 17,98 A")', () => {
    const r = P('Apfelschorle 12x0,7 l\n2 Kst x 8,99 17,98 A\nPfand Kasten\n4 Stk x 1,50 6,00 A\nSUMME EUR 23,98');
    expect(r.positions).toEqual([{ item: 'Apfelschorle 12x0,7 l', amount_cents: 1798 }, { item: 'Pfand Kasten', amount_cents: 600 }]);
    expect(r.total_cents).toBe(2398);
  });

  it('Betrag unlesbar: Menge x Einzelpreis einsetzen', () => {
    const r = P('Transparentpapier bunt\n10 Stk x 1,95 19,594 |\nSUMME EUR 19,50');
    expect(r.positions).toEqual([{ item: 'Transparentpapier bunt', amount_cents: 1950 }]);
  });

  it('Stoerfetzen links/rechts neben dem Bon werden verworfen', () => {
    const r = P('EEE Laugenbrezel EEE\nEEE 40 Stk x 0,85 34,00 B SE\nButterbrezel\n6 Stk x 1,20 7,20 B | ES\nSUMME EUR 41,20 5');
    expect(r.positions.map((p) => p.amount_cents)).toEqual([3400, 720]);
    expect(r.positions[0].item).toBe('Laugenbrezel');
    expect(r.total_cents).toBe(4120);
  });

  it('Artikel mit "tel"/"bar" im Wort ist keine Kopfzeile (Frischhaltebeutel 50er 2,29 A)', () => {
    expect(P('Frischhaltebeutel 50er 2,29 A\nSUMME 2,29').positions).toEqual([{ item: 'Frischhaltebeutel 50er', amount_cents: 229 }]);
    expect(P('Barista Milch 1,29\nSUMME 1,29').positions).toHaveLength(1);
  });

  it('Ziffern-Verwechslungen O/l in Betraegen und Datum', () => {
    const r = P('Kaffee 1O,5O A\nMilch l,99\nSUMME 12,49\n1O.O9.2O26');
    expect(r.positions.map((p) => p.amount_cents)).toEqual([1050, 199]);
    expect(r.date).toBe('2026-09-10');
  });

  it('Summe steht allein ueber dem Wort SUMME', () => {
    expect(P('Brot 2,50 A\n2,50\nSUMME EUR\nZahlart: BAR').total_cents).toBe(250);
  });

  it('Zahlungs-, Steuer- und TSE-Zeilen sind keine Positionen', () => {
    const r = P('Brot 2,50 A\nSUMME EUR 2,50\nZahlart: BAR\nGegeben bar 5,00\nRückgeld 2,50\nSatz Netto MwSt Brutto\nA 19% 2,10 0,40 2,50');
    expect(r.positions).toHaveLength(1);
  });

  it('Datumsformate: Monatsname, zweistelliges Jahr, TSE-Zeitstempel zaehlt nicht vor dem Belegdatum', () => {
    expect(findDates('am 2. Okt 2026')).toEqual(['2026-10-02']);
    expect(findDates('3. März 26')).toEqual(['2026-03-03']);
    expect(P('Start: 2026-10-02T15:18:28\nDatum 05.10.2026').date).toBe('2026-10-05');
  });

  it('Wechselgeld-/Zahlungszeilen (Zurück, EC, Karte) sind keine Positionen, Rabatte bleiben', () => {
    const r = P('Korb 20x14cm 1,99 A\nTotal EUR 1,99\nGegeben EUR 5,00\n1 Zurück EUR -3,01\nEC 1,99\nKarte 1,99');
    expect(r.positions).toEqual([{ item: 'Korb 20x14cm', amount_cents: 199 }]);
    expect(P('Kaffee 4,99\nRabatt -0,50').positions.map((x) => x.amount_cents)).toEqual([499, -50]);
  });
});
