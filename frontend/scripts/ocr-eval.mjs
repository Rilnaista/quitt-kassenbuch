#!/usr/bin/env node
// Mess-Harness lokale Belegerkennung (REQ-010/011): echte Browser-Kette in Headless-Chromium
// (shrinkImage -> Vorverarbeitung -> tesseract.js mit public/ocr -> receiptParse) gegen tests/fixtures/receipts.
//   node scripts/ocr-eval.mjs [--pre=none|gray|flat] [--scale=1.5] [--psm=3|4|6] [--set=photo|flat|all] [--cache] [-v]
//   --cache: gespeicherte OCR-Texte (letzter Lauf je Variante) nur neu parsen (schnell, fuer Parser-Arbeit)
// Voraussetzung: Playwright (npm i -g playwright && npx playwright install chromium), public/ocr (npm run build/predev).
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, existsSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import { parseReceiptText } from '../src/lib/receiptParse.js';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const FIX = join(ROOT, 'tests/fixtures/receipts');
const args = Object.fromEntries(process.argv.slice(2).map((a) => { const [k, v] = a.replace(/^-+/, '').split('='); return [k, v ?? true]; }));
const opts = { preprocess: args.pre || 'none', psm: args.psm ? String(args.psm) : undefined, scale: args.scale ? +args.scale : 1 };
const set = args.set || 'all';
const cacheFile = join(FIX, `_ocr-cache-${opts.preprocess}-${opts.psm || 'default'}-${opts.scale}.json`);

const data = JSON.parse(readFileSync(join(FIX, 'demo-data.json'), 'utf8'));
const cents = (s) => Math.round(parseFloat(s) * 100);
const norm = (s) => String(s).toLowerCase().replace(/ä/g, 'a').replace(/ö/g, 'o').replace(/ü/g, 'u').replace(/ß/g, 'ss').replace(/[^a-z0-9]/g, '');
function sim(a, b) { // Levenshtein-Aehnlichkeit 0..1
  a = norm(a); b = norm(b);
  if (!a || !b) return 0;
  const d = Array.from({ length: a.length + 1 }, (_, i) => [i, ...Array(b.length).fill(0)]);
  for (let j = 1; j <= b.length; j++) d[0][j] = j;
  for (let i = 1; i <= a.length; i++) for (let j = 1; j <= b.length; j++) d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
  return 1 - d[a.length][b.length] / Math.max(a.length, b.length);
}

function score(exp, got) {
  const used = new Set();
  let found = 0; let correct = 0;
  for (const p of exp.positionen) {
    const c = cents(p.summe);
    const idx = got.positions.findIndex((g, i) => !used.has(i) && g.amount_cents === c);
    if (idx < 0) continue;
    used.add(idx); found++;
    if (sim(p.text, got.positions[idx].item) >= 0.7) correct++;
  }
  const n = exp.positionen.length;
  const extra = got.positions.length - found;
  return {
    date: got.date === exp.datum, n, found, correct, extra,
    total: got.total_cents === cents(exp.summe),
    // Beleg-Score: Datum, Positionen (korrekt/max(erwartet, gelesen)), Summe -> gleich gewichtet
    score: ((got.date === exp.datum ? 1 : 0) + correct / Math.max(n, got.positions.length) + (got.total_cents === cents(exp.summe) ? 1 : 0)) / 3,
  };
}

async function loadPlaywright() {
  try { return await import('playwright'); } catch { /* global? */ }
  const g = execFileSync('npm', ['root', '-g'], { encoding: 'utf8' }).trim();
  return createRequire(join(g, 'x.js'))('playwright');
}

const jobs = [];
for (const kind of ['photo', 'flat']) {
  if (set !== 'all' && set !== kind) continue;
  for (const e of data.ausgaben) {
    const f = readdirSync(join(FIX, kind)).find((n) => n.startsWith(e.id));
    if (f) jobs.push({ kind, exp: e, file: `${kind}/${f}` });
  }
}

let texts = {};
if (args.cache && existsSync(cacheFile)) texts = JSON.parse(readFileSync(cacheFile, 'utf8'));
else {
  const server = await createServer({ root: ROOT, logLevel: 'error', server: { port: 0, host: '127.0.0.1' } });
  await server.listen();
  const url = `http://127.0.0.1:${server.config.server.port}`;
  const { chromium } = await loadPlaywright();
  const browser = await chromium.launch();
  try {
    const page = await (await browser.newContext()).newPage();
    page.on('pageerror', (e) => console.error('pageerror', e.message));
    // leere Seite im Wurzelpfad (document.baseURI wie in der App -> ocr/ liegt unter /ocr/)
    await page.route('**/__eval', (route) => route.fulfill({ contentType: 'text/html', body: '<!doctype html><meta charset="utf-8"><body></body>' }));
    await page.goto(`${url}/__eval`);
    for (const j of jobs) {
      const r = await page.evaluate(async ({ file, opts }) => {
        const { shrinkImage } = await import('/src/lib/image.js');
        const { recognizeText } = await import('/src/lib/ocr.js');
        const blob = await (await fetch('/tests/fixtures/receipts/' + file)).blob();
        const t0 = performance.now();
        const small = await shrinkImage(new File([blob], file.split('/').pop(), { type: blob.type }));
        const text = await recognizeText(small.blob, () => {}, opts);
        return { text, ms: Math.round(performance.now() - t0) };
      }, { file: j.file, opts });
      texts[j.file] = r;
      process.stderr.write(`  ${j.file} ${r.ms} ms\n`);
    }
  } finally { await browser.close(); await server.close(); }
  writeFileSync(cacheFile, JSON.stringify(texts, null, 1));
}

const rows = [];
for (const j of jobs) {
  const t = texts[j.file];
  const got = parseReceiptText(t.text);
  const s = score(j.exp, got);
  rows.push({ ...j, ...s, ms: t.ms });
  if (args.v) console.log(`--- ${j.file}\n${t.text}\n=> ${JSON.stringify(got)}`);
}
console.log('Beleg                         Datum  Pos(korr/gef/erw/+)  Summe  Score  ms');
for (const r of rows) {
  console.log(`${r.file.padEnd(30)}${(r.date ? 'ok' : '--').padEnd(7)}${`${r.correct}/${r.found}/${r.n}/+${r.extra}`.padEnd(21)}${(r.total ? 'ok' : '--').padEnd(7)}${(r.score * 100).toFixed(0).padStart(4)}%  ${r.ms ?? ''}`);
}
for (const kind of ['photo', 'flat']) {
  const rs = rows.filter((r) => r.kind === kind);
  if (rs.length) console.log(`Gesamt ${kind}: ${(rs.reduce((a, r) => a + r.score, 0) / rs.length * 100).toFixed(1)}%  (mittlere Zeit ${Math.round(rs.reduce((a, r) => a + (r.ms || 0), 0) / rs.length)} ms)`);
}
console.log(`Gesamtscore: ${(rows.reduce((a, r) => a + r.score, 0) / rows.length * 100).toFixed(1)}%`);
