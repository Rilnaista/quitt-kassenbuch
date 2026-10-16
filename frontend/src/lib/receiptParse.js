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

const MONTH_NAMES = { jan: 1, feb: 2, mar: 3, maer: 3, apr: 4, mai: 5, jun: 6, jul: 7, aug: 8, sep: 9, okt: 10, nov: 11, dez: 12 };

/** OCR-Verwechslungen in Zahlen-Token: O/o -> 0, l/I/| -> 1 (nur in Token, die sonst aus Ziffern und Trennern bestehen). */
function fixDigits(tok) {
  return tok.replace(/[OoDQ]/g, '0').replace(/[lI|]/g, '1');
}
function fixDateTokens(line) {
  return line.replace(/(?<![\w])[\dOoIl]{1,2}\s?[.\-/]\s?[\dOoIl]{1,2}\s?[.\-/]\s?[\dOoIl]{2,4}(?![\w])/g, (t) => (/\d/.test(t) ? fixDigits(t) : t));
}

/** Alle gueltigen Datumsangaben einer Zeile: dd.mm.yyyy, dd.mm.yy, dd-mm-yyyy, dd/mm/yyyy, yyyy-mm-dd, "2. Okt 2026". */
export function findDates(line) {
  const out = [];
  line = fixDateTokens(line);
  for (const m of line.matchAll(/(?<!\d)(\d{1,2})\.?\s*(jan|feb|m[aä]r|apr|mai|jun|jul|aug|sep|okt|nov|dez)[a-zä]*\.?\s*(\d{4}|\d{2})(?!\d)/gi)) {
    const mo = MONTH_NAMES[m[2].toLowerCase().replace('ä', 'ae')];
    let y = +m[3]; if (m[3].length === 2) y += 2000;
    if (validDate(y, mo, +m[1])) out.push(iso(y, mo, +m[1]));
  }
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
    if (/datum|date|am:|\bbon\b|beleg/i.test(l) && !/start|ende|tse/i.test(l)) return found[0];
    if (!first) first = found[0];
  }
  return first;
}

// Preis am Zeilenende: 1,99 | 1.99 | 1.234,56 | -0,30 | 0,30- ; optional EUR/€ und Steuerklasse (A, B, 1, 2, *)
const PRICE_END = /(-?)\s*(\d{1,3}(?:\.\d{3})+|\d+)\s?[,.]\s?(\d{2})\s*(-?)\s*(?:€|EUR|EURO)?\s*(?:[A-Z*]|[12])?\s*$/;

const TOTAL = /^\s*(?:(?:end|gesamt|brutto)\s*)?(summe|summen|gesamtsumme|gesamtbetrag|gesamt|total|zu zahlen|zahlbetrag|endbetrag|betrag|rechnungsbetrag|zu zahlender betrag)\b/i;
const SKIP_LINE = /zwischensum|\bzur[uü]ck\b|\bec\b|\bkarte\b|\br[uü]ckgeld|\bwechselgeld|\bgegeben|\bbar\b|\bbezahlt|\bgezahlt|\bzahlung|\bzahlart|\bec[-\s]?karte|\bgirocard|\bkreditkarte|\bkartenzahlung|\bvisa\b|\bmastercard|\bmaestro|\bmwst|\bmehrwertsteuer|\bust\b|\bnetto\b|\bbrutto\b|\bsteuer|\bsatz\b|\bkundenkarte|\bpayback|\bpunkte|\bbonus|\btse\b|\bseriennr|\bterminal|\bkasse\s*\d|\bbon[-\s]?nr|\bbeleg[-\s]?nr|\bartikelanzahl|\banzahl\b|\bpositionen\b|\btel\b\.?|\btelefon|\bfax\b|www\.|\biban\b|ust-?id|\bst\.-?nr|\bvielen dank|\bdanke\b|\bsignatur|\btransaktion|\balgorithmus|\bpr[uü]fwert/i;
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

// Mengenzeile: "10 Stk x 1,95", "2 Kst x 8,99", "0,832 kg x 1,99 EUR/kg", "3 x 0,95" (Name steht davor)
const QTY_LINE = /^\s*(\d+(?:[.,]\d+)?)\s*([A-Za-z]{0,4})\.?\s*[x×*]\s*(\d+[.,]\d{2})(.*)$/i;

/** Zeile von OCR-Stoerzeichen befreien: Rand-Muell (Hintergrund neben dem Bon), Ziffern-Verwechslungen in Betraegen. */
export function cleanLine(raw) {
  let toks = raw.replace(/\s+/g, ' ').trim().split(' ').filter(Boolean);
  const junk = (t) => !/[\p{L}\d]/u.test(t) || /^(\p{L})\1+$/u.test(t) || /^[|!¦]+$/.test(t);
  while (toks.length > 1 && junk(toks[0])) toks.shift();
  while (toks.length > 1 && junk(toks[toks.length - 1])) toks.pop();
  toks = toks.map((t) => {
    const m = /^(-?)([\dOoIl|]{1,6}(?:\.[\dOoIl|]{3})*)([,.])([\dOoIl|]{2})(-?)([A-Z*]?)$/.exec(t);
    return m && /\d/.test(t) ? m[1] + fixDigits(m[2]) + m[3] + fixDigits(m[4]) + m[5] + m[6] : t;
  });
  // kurze Stoerfetzen NACH dem Preis ("19,50 A Ö SA"): hinter dem Preis hoechstens Steuerklasse/Waehrung behalten
  const priceAt = toks.findIndex((t, i) => i > 0 && /^-?(?:\d{1,3}(?:\.\d{3})+|\d+)[,.]\d{2}-?$/.test(t) && toks.slice(i + 1).every((x) => (!/\d/.test(x) && x.length <= 4) || /^[3-9]$/.test(x)));
  if (priceAt >= 0) {
    const tail = toks.slice(priceAt + 1);
    const keep = tail.length && /^(?:[A-Z*]|€|EUR|EURO)$/.test(tail[0]) ? [tail[0]] : [];
    toks = toks.slice(0, priceAt + 1).concat(keep);
  }
  return toks.join(' ');
}

export function parseReceiptText(text) {
  const lines = String(text || '').split(/\r?\n/).map(cleanLine).filter(Boolean);
  const positions = [];
  let total = null;
  let pendingName = null; // Artikelname ohne Preis, Preis folgt in der naechsten Zeile (Menge/Gewicht)
  let totalLabel = false; // "SUMME EUR" ohne Betrag: Betrag steht davor/danach allein in einer Zeile

  for (const line of lines) {
    const q = QTY_LINE.exec(line);
    let m = PRICE_END.exec(line);
    let cents;
    let head;
    if (q && pendingName && !SKIP_LINE.test(line)) {
      // Mengenzeile zum Artikel davor: Betrag am Zeilenende, sonst Menge x Einzelpreis (Betrag unlesbar)
      const tail = PRICE_END.exec(q[4].trim().length ? q[4].trim() : '');
      const qty = parseFloat(q[1].replace(',', '.'));
      const unit = Math.round(parseFloat(q[3].replace(',', '.')) * 100);
      if (tail && tail.index === 0) cents = toCents(tail[1], tail[2], tail[3], tail[4]);
      else if (qty > 0) cents = Math.round(qty * unit);
      else cents = null;
      if (cents) {
        const item = cleanItem(pendingName);
        pendingName = null;
        if (LETTERS.test(item)) positions.push({ item, amount_cents: cents });
        continue;
      }
    }
    if (!m) {
      if (TOTAL.test(line) && !/zwischen/i.test(line)) { totalLabel = true; pendingName = null; continue; }
      pendingName = LETTERS.test(line) && !SKIP_LINE.test(line) && !findDates(line).length && line.length <= 60 ? line : null;
      continue;
    }
    cents = toCents(m[1], m[2], m[3], m[4]);
    head = line.slice(0, m.index).trim();

    if (TOTAL.test(line) && !/zwischen/i.test(line)) {
      if (total == null && cents > 0) total = cents;
      totalLabel = false;
      if (positions.length) break; // alles danach: Zahlart, Steuer, Fusszeile
      continue;
    }
    if (totalLabel && !LETTERS.test(head) && cents > 0) { // Betrag der Summenzeile steht allein in der naechsten Zeile
      if (total == null) total = cents;
      totalLabel = false;
      if (positions.length) break;
      continue;
    }
    if (SKIP_LINE.test(line) || findDates(line).length) { pendingName = null; continue; }

    let item = cleanItem(head);
    // Mengen-/Gewichtszeile ohne Name davor-Treffer ("0,832 kg x 1,99 EUR/kg  1,66")
    const qtyLine = /^[\d.,\s]*(?:kg|g|l|stk|st|x|×)\b/i.test(head);
    if (qtyLine && pendingName) item = cleanItem(pendingName);
    else if (!LETTERS.test(item)) {
      pendingName = null; continue;
    }
    pendingName = null;
    let amount = cents;
    if (amount > 0 && DISCOUNT.test(item)) amount = -amount;
    if (amount === 0) continue;
    positions.push({ item, amount_cents: amount });
  }
  // Layout "56,24" allein ueber "SUMME EUR": Betrag der Zeile vor dem Wort nachtragen
  if (total == null) total = totalBeforeLabel(lines);
  return { date: parseDate(text), positions, total_cents: total };
}

/** Summenbetrag, der allein (ohne Wort) in der Zeile unmittelbar vor "SUMME ..." steht. */
function totalBeforeLabel(lines) {
  for (let i = 1; i < lines.length; i++) {
    if (!TOTAL.test(lines[i]) || PRICE_END.test(lines[i]) || /zwischen/i.test(lines[i])) continue;
    const m = PRICE_END.exec(lines[i - 1]);
    if (m && m.index === 0) return toCents(m[1], m[2], m[3], m[4]);
  }
  return null;
}
