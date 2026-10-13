import { describe, it, expect } from 'vitest';
import { buildLedger, balance, totals, categorySums, suggestions, filterLedger } from '../src/lib/ledger.js';
import { parseCents, fmtCents, fmtCentsPlain } from '../src/lib/money.js';
import { validateEntry, normalizeEntry } from '../src/db/repo.js';

const E = (id, date, type, cents, o = {}) => ({ id, date, type, amount_cents: cents, number: null, created_at: '2026-01-01T00:00:00Z', deleted_at: null, ...o });
const sample = [
  E('c', '2026-02-01', 'out', 1890, { number: 3, reason: 'Geburtstag', category: 'Frau Müller', item: 'Blumen' }),
  E('a', '2026-01-01', 'in', 14320, { number: 1, purpose: 'Restgeld Vorjahr' }),
  E('b', '2026-01-12', 'in', 4800, { number: 2, purpose: 'Sammlung' }),
  E('d', '2026-02-01', 'out', 950, { number: 4, reason: 'Geburtstag', category: 'Frau Müller', item: 'Pralinen' }),
  E('e', '2026-03-01', 'out', 1140, { number: 5, reason: 'Fasching', category: 'Team Sonne', item: 'Konfetti' }),
  E('x', '2026-03-02', 'out', 99999, { number: 6, reason: 'Fasching', category: 'Team Sonne', item: 'Doppelt', deleted_at: '2026-03-03T00:00:00Z' }),
];

describe('Saldo & Summen (Cent-genau)', () => {
  it('Kontoverlauf chronologisch mit laufendem Saldo, Papierkorb zaehlt nicht', () => {
    const l = buildLedger(sample);
    expect(l.map((e) => e.id)).toEqual(['a', 'b', 'c', 'd', 'e']);
    expect(l.map((e) => e.balance_cents)).toEqual([14320, 19120, 17230, 16280, 15140]);
    expect(balance(sample)).toBe(15140);
  });
  it('Summen Ein/Aus ohne Papierkorb', () => {
    expect(totals(sample)).toEqual({ in: 19120, out: 3980 });
  });
  it('keine Fliesskomma-Fehler (0,10 + 0,20)', () => {
    const l = buildLedger([E('1', '2026-01-01', 'in', 10), E('2', '2026-01-02', 'in', 20), E('3', '2026-01-03', 'out', 30)]);
    expect(l.map((e) => e.balance_cents)).toEqual([10, 30, 0]);
  });
  it('gleicher Tag: Belegnummer vor Anlagezeit; ohne Nummer ans Ende', () => {
    const l = buildLedger([
      E('n', '2026-01-01', 'in', 1, { created_at: '2026-01-01T00:00:00Z' }),
      E('m', '2026-01-01', 'in', 1, { number: 9, created_at: '2026-01-02T00:00:00Z' }),
      E('k', '2026-01-01', 'in', 1, { number: 2, created_at: '2026-01-03T00:00:00Z' }),
    ]);
    expect(l.map((e) => e.id)).toEqual(['k', 'm', 'n']);
  });
  it('Ausgaben je Kategorie absteigend, nur Ausgaben, ohne Papierkorb', () => {
    expect(categorySums(sample)).toEqual([
      { name: 'Frau Müller', sum_cents: 2840, count: 2 },
      { name: 'Team Sonne', sum_cents: 1140, count: 1 },
    ]);
  });
  it('Vorschlaege: haeufigste zuerst', () => {
    expect(suggestions(sample, 'reason')).toEqual(['Fasching', 'Geburtstag']);
    expect(suggestions(sample, 'category')[0]).toBe('Frau Müller');
  });
  it('Filter & Suche', () => {
    const l = buildLedger(sample);
    expect(filterLedger(l, { type: 'in' }).map((e) => e.id)).toEqual(['a', 'b']);
    expect(filterLedger(l, { category: 'Team Sonne' }).map((e) => e.id)).toEqual(['e']);
    expect(filterLedger(l, { q: 'pralinen' }).map((e) => e.id)).toEqual(['d']);
    expect(filterLedger(l, { q: '18,90' }).map((e) => e.id)).toEqual(['c']);
    expect(filterLedger(l, { q: '12.01.2026' }).map((e) => e.id)).toEqual(['b']);
  });
});

describe('Betragseingabe', () => {
  it.each([
    ['12', 1200], ['12,5', 1250], ['12,50', 1250], ['1.234,56', 123456], ['12.50', 1250], ['0,99', 99], ['12 €', 1200], ['1.234', 123400],
  ])('%s -> %i Cent', (s, c) => expect(parseCents(s)).toBe(c));
  it.each(['', 'abc', '12,345', '-3', '1,2,3'])('ungueltig: "%s"', (s) => expect(parseCents(s)).toBeNull());
  it('Formatierung', () => {
    expect(fmtCents(123456).replace(/\s/g, ' ')).toBe('1.234,56 €');
    expect(fmtCentsPlain(5)).toBe('0,05');
  });
});

describe('Buchung validieren/normalisieren', () => {
  it('Ausgabe braucht Grund, Kategorie, Bezug, Betrag > 0', () => {
    expect(validateEntry({ type: 'out', date: '2026-01-01', amount_cents: 0 })).toEqual({ amount_cents: 'form.errAmount', reason: 'form.errRequired', category: 'form.errRequired', item: 'form.errRequired' });
  });
  it('Einnahme braucht Verwendungszweck', () => {
    expect(validateEntry({ type: 'in', date: '2026-01-01', amount_cents: 100, purpose: ' ' })).toEqual({ purpose: 'form.errRequired' });
  });
  it('Felder des anderen Typs werden geleert', () => {
    const n = normalizeEntry({ id: '1', type: 'in', date: '2026-01-01', amount_cents: 5, purpose: ' Sammlung ', reason: 'x', category: 'y', item: 'z', receipt_id: 'r' });
    expect(n).toMatchObject({ purpose: 'Sammlung', reason: null, category: null, item: null, receipt_id: null });
  });
});
