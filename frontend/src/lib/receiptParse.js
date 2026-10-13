// Parser fuer OCR-Text von Kassenbons (lokale Erkennung). Rein, ohne Abhaengigkeiten, getestet.
// Ergebnis: { date: 'YYYY-MM-DD'|null, positions: [{ item, amount_cents }], total_cents: int|null }
// Negative Betraege (Rabatt/Pfandrueckgabe) bleiben negativ -> Einrechnung siehe receiptCards.js.

const MONTH_DAYS = [31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

function validDate(y, m, d) {
  if (m < 1 || m > 12 || d < 1 || d > MONTH_DAYS[m - 1]) return false;
  if (m === 2 && d === 29 && !(y % 4 === 0 && (y % 100 !== 0 || y % 400 === 0))) return false;
  return y >= 2000 && y <= 2099;
}
const iso = (y, m, d) => `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;

/** Alle gueltigen Datumsangaben einer Zeile: dd.mm.yyyy, dd.mm.yy, dd-mm-yyyy, dd/mm/yyyy, yyyy-mm-dd. */
export function findDates(line) {
  const out = [];
  for (const m of line.matchAll(/(?<![\d.,])(\d{1,2})\s?[.\-/]\s?(\d{1,2})\s?[.\-/]\s?(\d{4}|\d{2})(?![\d])/g)) {
    const d = +m[1]; const mo = +m[2]; let y = +m[3];
    if (m[3].length === 2) y += 2000;
    if (validDate(y, mo, d)) out.push(iso(y, mo, d));
  }
  for (const m of line.matchAll(/(?<!\d)(\d{4})-(\d{2})-(\d{2})(?!\d)/g)) {
    if (validDate(+m[1], +m[2], +m[3])) out.push(iso(+m[1], +m[2], +m[3]));
  }
  return out;
}

export function parseDate(text) {
  const lines = String(text || '').split(/\r?\n/);
  let first = null;
  for (const l of lines) {
    const found = findDates(l);
    if (!found.length) continue;
    if (/datum|date|am:|bon|beleg/i.test(l)) return found[0];
    if (!first) first = found[0];
  }
  return first;
}

// Preis am Zeilenende: 1,99 | 1.99 | 1.234,56 | -0,30 | 0,30- ; optional EUR/€ und Steuerklasse (A, B, 1, 2, *)
const PRICE_END = /(-?)\s*(\d{1,3}(?:\.\d{3})+|\d+)\s?[,.]\s?(\d{2})\s*(-?)\s*(?:€|EUR|EURO)?\s*(?:[A-Z*]|[12])?\s*$/;

const TOTAL = /^\s*(?:(?:end|gesamt|brutto)\s*)?(summe|summen|gesamtsumme|gesamtbetrag|gesamt|total|zu zahlen|zahlbetrag|endbetrag|betrag|rechnungsbetrag|zu zahlender betrag)\b/i;
const SKIP_LINE = /zwischensumme|zwischensum|r[uü]ckgeld|wechselgeld|gegeben|\bbar\b|bezahlt|gezahlt|zahlung|ec[-\s]?karte|girocard|kreditkarte|kartenzahlung|visa|mastercard|maestro|mwst|mehrwertsteuer|\bust\b|netto|steuer|kundenkarte|payback|punkte|bonus|tse|seriennummer|terminal|kasse\s*\d|bon[-\s]?nr|beleg[-\s]?nr|artikelanzahl|anzahl|positionen|tel\.?|telefon|fax|www\.|iban|ust-?id|st\.-?nr|vielen dank|danke/i;
const DISCOUNT = /rabatt|nachlass|gutschein|coupon|preisvorteil|abzug|leergut|pfand\s*-?\s*r[uü]ck|r[uü]ckgabe|storno/i;
const LETTERS = /[A-Za-zÄÖÜäöüß]{2,}/;

function toCents(sign1, intPart, dec, sign2) {
  const cents = parseInt(intPart.replace(/\./g, ''), 10) * 100 + parseInt(dec, 10);
  return sign1 === '-' || sign2 === '-' ? -cents : cents;
}

function cleanItem(s) {
  return s
    .replace(/^\s*\d{4,}\s+/, '') // Artikelnummer
    .replace(/\s+\d+\s*[x×]\s*\d+[.,]\d{2}\s*(?:€|EUR)?\s*$/i, '') // "4 x 3,99" (Menge x Einzelpreis)
    .replace(/\s*\b\d+\s*[x×]\s*$/i, '') // "2 x" vor dem Preis
    .replace(/\s*(?:€|EUR)\/?\w*\s*$/i, '')
    .replace(/[|_~=]+/g, ' ')
    .replace(/\s{2,}/g, ' ')
    .replace(/^[\s.:,;*-]+|[\s.:,;*-]+$/g, '')
    .trim();
}

export function parseReceiptText(text) {
  const lines = String(text || '').split(/\r?\n/).map((l) => l.replace(/\s+/g, ' ').trim()).filter(Boolean);
  const positions = [];
  let total = null;
  let pendingName = null; // Artikelname ohne Preis, Preis folgt in der naechsten Zeile (Menge/Gewicht)

  for (const line of lines) {
    const m = PRICE_END.exec(line);
    if (!m) {
      pendingName = LETTERS.test(line) && !SKIP_LINE.test(line) && !TOTAL.test(line) && !findDates(line).length && line.length <= 60 ? line : null;
      continue;
    }
    const cents = toCents(m[1], m[2], m[3], m[4]);
    const head = line.slice(0, m.index).trim();

    if (TOTAL.test(line) && !/zwischen/i.test(line)) {
      if (total == null && cents > 0) total = cents;
      if (positions.length) break; // alles danach: Zahlart, Steuer, Fusszeile
      continue;
    }
    if (SKIP_LINE.test(line) || findDates(line).length) { pendingName = null; continue; }

    let item = cleanItem(head);
    // Mengen-/Gewichtszeile ("0,832 kg x 1,99 EUR/kg  1,66"): Name steht in der Zeile davor
    const qtyLine = /^[\d.,\s]*(?:kg|g|l|stk|st|x|×)\b/i.test(head);
    if (qtyLine && pendingName) item = cleanItem(pendingName);
    else if (!LETTERS.test(item)) { pendingName = null; continue; }
    pendingName = null;
    let amount = cents;
    if (amount > 0 && DISCOUNT.test(item)) amount = -amount;
    if (amount === 0) continue;
    positions.push({ item, amount_cents: amount });
  }
  return { date: parseDate(text), positions, total_cents: total };
}
