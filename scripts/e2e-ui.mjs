#!/usr/bin/env node
// UI-Integrationstest: echtes Frontend gegen echtes Backend (Release-Paket, php -S, Unterordner /quitt/).
//
//   node scripts/e2e-ui.mjs                (baut Paket 0.1.0 neu; E2E_PACKAGE=<zip> verwendet ein vorhandenes)
//   E2E_HEADED=1 / E2E_KEEP=1 / E2E_DEBUG=1   Browser sichtbar / Arbeitsordner behalten / DOM-Auszug bei Fehler
//   npm run e2e:ui  (im Ordner frontend/)
//
// Voraussetzungen: php (mit zip), node >= 20, Playwright (npm i -g playwright && npx playwright install chromium).
// Ablauf siehe unten (Schritte 1-14); jeder Schritt wird als "ok" oder mit Fehler ausgegeben.
import { spawn, execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdtempSync, mkdirSync, copyFileSync, existsSync, rmSync, readFileSync, writeFileSync, readdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { deflateSync } from 'node:zlib';
import assert from 'node:assert/strict';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const PIN = '1234';
const NEW_PIN = '5678';
const VERSION = '0.1.0';

async function loadPlaywright() {
  try { return await import('playwright'); } catch { /* global installiert? */ }
  const g = execFileSync('npm', ['root', '-g'], { encoding: 'utf8' }).trim();
  return createRequire(join(g, 'x.js'))('playwright');
}
const { chromium } = await loadPlaywright();

const WORK = mkdtempSync(join(tmpdir(), 'quitt-ui-'));
const PORT = 18000 + Math.floor(Math.random() * 1000);
const ORIGIN = `http://127.0.0.1:${PORT}`;
const BASE = `${ORIGIN}/quitt/`;
const API = `${BASE}api/v1`;
let server = null;
let browser = null;
const consoleErrors = [];
let lastPage = null; // fuer Fehlerdiagnose

function log(m) { console.log('  ok  ' + m); }
async function cleanup() {
  try { if (browser) await browser.close(); } catch { /* egal */ }
  if (server) server.kill();
  if (process.env.E2E_KEEP === '1') console.log('Arbeitsordner: ' + WORK); else rmSync(WORK, { recursive: true, force: true });
}

// ---- 1. Paket + Server + setup.php ------------------------------------------
let pkg = process.env.E2E_PACKAGE;
if (!pkg) {
  execFileSync(join(ROOT, 'scripts/build-release.sh'), [VERSION], { stdio: 'ignore' });
  pkg = join(ROOT, 'dist-release', `quitt-${VERSION}.zip`);
}
const dir = join(WORK, 'www', 'quitt');
mkdirSync(dir, { recursive: true });
copyFileSync(join(ROOT, 'setup/setup.php'), join(dir, 'setup.php'));
copyFileSync(pkg, join(dir, `quitt-${VERSION}.zip`));
copyFileSync(pkg + '.sha256', join(dir, `quitt-${VERSION}.zip.sha256`));
server = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', join(WORK, 'www'), join(ROOT, 'scripts/release/dev-router.php')], {
  env: { ...process.env, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore',
});
for (let i = 0; i < 50; i++) { try { await fetch(ORIGIN + '/'); break; } catch { await new Promise((r) => setTimeout(r, 100)); } }


async function setupInstall() {
  const jar = {};
  const post = async (fields) => {
    const r = await fetch(BASE + 'setup.php', {
      method: 'POST', redirect: 'manual',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; ') },
      body: new URLSearchParams(fields).toString(),
    });
    for (const c of r.headers.getSetCookie?.() || []) { const [kv] = c.split(';'); const i = kv.indexOf('='); jar[kv.slice(0, i)] = kv.slice(i + 1); }
    return r;
  };
  const get = async () => {
    const r = await fetch(BASE + 'setup.php', { headers: { Cookie: Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; ') } });
    for (const c of r.headers.getSetCookie?.() || []) { const [kv] = c.split(';'); const i = kv.indexOf('='); jar[kv.slice(0, i)] = kv.slice(i + 1); }
    return r.text();
  };
  await post({ action: 'start' });
  let html = await get();
  const csrfOf = (h) => /name="csrf" value="([0-9a-f]+)"/.exec(h)?.[1];
  let csrf = csrfOf(html);
  assert.ok(csrf, 'setup: kein CSRF-Token');
  await post({ action: 'mode', mode: 'new', csrf });
  await get();
  await post({ action: 'package', csrf });
  html = await get();
  assert.ok(html.includes('name="pin"'), 'setup: Einrichtungsformular fehlt: ' + html.replace(/<style[\s\S]*?<\/style>/g, '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').slice(0, 600));
  await post({ action: 'install', group_name: 'UI-Test Kasse', pin: PIN, pin2: PIN, recognition_mode: 'local', csrf });
  html = await get();
  assert.ok(/Wiederherstellungscode/.test(html), 'setup: Code-Seite fehlt');
  html = await (await post({ action: 'finish', csrf })).text();
  assert.ok(/Quitt ist eingerichtet/.test(html), 'setup: Abschluss fehlt');
}

async function apiCall(method, path, token, body) {
  const r = await fetch(API + path, {
    method, headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: 'Bearer ' + token } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await r.text();
  let json = null; try { json = JSON.parse(text); } catch { /* kein JSON */ }
  return { status: r.status, json, text };
}
const login = async (pin) => (await apiCall('POST', '/auth/login', null, { pin })).json?.token;

/** Einfaches, echtes PNG (Streifen), damit der Browser es dekodieren kann. */
function testPng(w = 240, h = 320) {
  const raw = Buffer.alloc((w * 3 + 1) * h);
  for (let y = 0; y < h; y++) {
    raw[y * (w * 3 + 1)] = 0;
    for (let x = 0; x < w; x++) {
      const o = y * (w * 3 + 1) + 1 + x * 3;
      const dark = (y % 24) < 3 || (x > 20 && x < 200 && (y % 24) > 8 && (y % 24) < 12);
      raw[o] = raw[o + 1] = raw[o + 2] = dark ? 40 : 245;
    }
  }
  const crcT = [...Array(256)].map((_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0; });
  const crc = (b) => { let c = 0xffffffff; for (const x of b) c = crcT[(c ^ x) & 255] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
  const chunk = (t, d) => { const len = Buffer.alloc(4); len.writeUInt32BE(d.length); const td = Buffer.concat([Buffer.from(t), d]); const c = Buffer.alloc(4); c.writeUInt32BE(crc(td)); return Buffer.concat([len, td, c]); };
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2;
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}

// ---- Hilfen fuer die Oberflaeche ----------------------------------------------
const view = (page) => page.locator('.view.tab-active .page-current').last();
/** Schaltflaeche (f7-button = <a class="button">); Text-Treffer am Ende, weil Icon-Ligaturen davor stehen. */
const btn = (scope, text) => scope.locator('a.button, button').filter({ hasText: typeof text === 'string' ? new RegExp(text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*$') : text });
async function waitFor(fn, what, tries = 40) {
  for (let i = 0; i < tries; i++) { if (await fn()) return; await sleep(500); }
  throw new Error('Zeitüberschreitung: ' + what);
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function field(page, label) {
  return view(page).locator(`.item-input:has(.item-title:text-is("${label}")) input, .item-input:has(.item-title:text-is("${label}")) select`).first();
}
async function fill(page, label, value) {
  const f = await field(page, label);
  await f.click();
  await f.fill(value);
  await f.dispatchEvent('input');
  await f.blur().catch(() => {});
}
async function pressPin(page, pin) {
  const lock = page.locator('.lock-screen');
  await lock.waitFor({ state: 'visible' });
  for (const d of pin) await lock.locator(`button.lock-key[aria-label="${d}"]`).click();
  const ok = lock.getByRole('button', { name: 'OK', exact: true }).first();
  if (await ok.isVisible().catch(() => false)) await ok.click();
}
async function confirmDialog(page, label = /^OK$/) {
  const btn = page.locator('.dialog-buttons .dialog-button').filter({ hasText: label }).last();
  await btn.waitFor({ state: 'visible' });
  await btn.click();
}
async function tab(page, text) {
  await page.locator('.toolbar-bottom a.tab-link, .toolbar a.tab-link').filter({ hasText: text }).first().click();
  await sleep(300);
}
async function newBooking(page, kind) {
  await page.locator('.global-fab').click();
  await page.locator('.actions-button').filter({ hasText: new RegExp(`^${kind}$`) }).click();
  await view(page).locator('.navbar .title').filter({ hasText: /Neue (Ausgabe|Einnahme)/ }).waitFor();
}
async function save(page) {
  await btn(view(page), 'Speichern').click();
  await page.locator('.toast').first().waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
  await sleep(500);
}
const eur = (cents) => (cents / 100).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

async function main() {
  console.log(`== UI-E2E ${BASE}`);
  await setupInstall();
  log('1 Installation per setup.php (Lokal-Modus)');

  browser = await chromium.launch({ headless: process.env.E2E_HEADED !== '1' });
  const downloads = [];
  /** Gemerkte Ticket-URLs der Seite abrufen (je Ticket nur einmal gueltig). */
  const collectTickets = async (pg) => {
    const urls = await pg.evaluate(() => { const u = window.__tickets.splice(0); return u; });
    for (const url of urls) {
      const r = await fetch(url);
      const buf = Buffer.from(await r.arrayBuffer());
      downloads.push({ url, status: r.status, type: r.headers.get('content-type') || '', head: buf.subarray(0, 4).toString('latin1'), cd: r.headers.get('content-disposition') || '', size: buf.length });
    }
  };
  const watch = (page, tag) => {
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(`[${tag}] ${m.text()}`); });
    page.on('pageerror', (e) => consoleErrors.push(`[${tag}] pageerror ${e.message}`));
  };

  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, acceptDownloads: true, serviceWorkers: process.env.E2E_NOSW ? 'block' : 'allow' });
  // Ticket-Downloads oeffnet die App per <a target=_blank>; Headless-Chromium oeffnet dafuer kein Fenster.
  // Darum wird der Klick abgefangen (URL gemerkt) und die Ticket-URL danach von hier abgerufen.
  await ctx.addInitScript(() => {
    window.__tickets = [];
    const orig = HTMLAnchorElement.prototype.click;
    HTMLAnchorElement.prototype.click = function () {
      if (/[?&]ticket=/.test(this.href)) { window.__tickets.push(this.href); return undefined; }
      return orig.call(this);
    };
  });
  ctx.on('page', (p) => watch(p, 'popup'));
  const page = await ctx.newPage();
  lastPage = page;
  watch(page, 'main');
  page.on('response', (r) => { if (r.url().includes('/api/v1/') && r.status() >= 400 && ![401, 403, 404, 409, 422, 429].includes(r.status())) consoleErrors.push(`[main] HTTP ${r.status()} ${r.url()}`); });

  // ---- 2. PIN-Login ------------------------------------------------------------
  await page.goto(BASE);
  await pressPin(page, '999999'.slice(0, 4));
  await page.locator('.lock-msg').filter({ hasText: 'Falsche PIN' }).waitFor({ timeout: 5000 });
  await sleep(1200);
  await pressPin(page, PIN);
  await page.locator('.hero-saldo').waitFor();
  assert.ok((await page.locator('.hero-saldo').first().innerText()).includes('0,00'));
  log('2 PIN-Login (falsche PIN abgewiesen, richtige akzeptiert)');

  // ---- 3. Einnahme -------------------------------------------------------------
  await newBooking(page, 'Einnahme');
  await fill(page, 'Betrag (€)', '150,00');
  await fill(page, 'Verwendungszweck', 'Restgeld Vorjahr');
  await save(page);
  await page.locator('.hero-saldo').first().waitFor();
  await page.waitForFunction(() => document.querySelector('.hero-saldo')?.textContent.includes('150,00'));
  log('3 Einnahme "Restgeld Vorjahr" 150,00 -> Saldo 150,00');

  // ---- 4. Ausgabe manuell ------------------------------------------------------
  await newBooking(page, 'Ausgabe');
  await fill(page, 'Betrag (€)', '12,50');
  await fill(page, 'Grund', 'Geburtstag');
  await fill(page, 'Kategorie', 'Anna');
  await fill(page, 'Bezug', 'Blumenstrauß');
  await save(page);
  await page.waitForFunction(() => document.querySelector('.hero-saldo')?.textContent.includes('137,50'));
  log('4 Ausgabe manuell 12,50 (Geburtstag/Anna/Blumenstrauß) -> Saldo 137,50');

  // ---- 5. Beleg-Flow, Erkennung "Aus" ------------------------------------------
  await tab(page, 'Mehr');
  await view(page).locator('a.item-link').filter({ hasText: 'Belegerkennung' }).click();
  await view(page).locator('label.item-radio:has(.item-title:text-is("Aus"))').click();
  await sleep(800);
  assert.equal((await apiCall('GET', '/settings', await login(PIN))).json.recognition_mode, 'off', 'Erkennungsmodus nicht "off" gespeichert');
  await view(page).locator('.navbar .left a.back, .navbar a.back').first().click();
  await tab(page, 'Übersicht');
  await page.locator('.global-fab').click();
  await page.locator('.actions-button').filter({ hasText: 'Beleg scannen' }).click();
  const png = join(WORK, 'beleg.png');
  writeFileSync(png, testPng());
  await view(page).locator('input[type=file]').nth(1).setInputFiles(png);
  await view(page).locator('.pos-card').first().waitFor({ timeout: 15000 });
  // Position 1
  await fill(page, 'Bezug', 'Kuchen');
  await view(page).locator('.pos-card').first().locator('.item-input:has(.item-title:text-is("Betrag (€)")) input').fill('8,40');
  await view(page).locator('.pos-card').first().locator('.item-input:has(.item-title:text-is("Betrag (€)")) input').dispatchEvent('input');
  const g1 = view(page).locator('.pos-card').first().locator('.item-input:has(.item-title:text-is("Grund")) input');
  await g1.fill('Sommerfest'); await g1.dispatchEvent('input');
  const c1 = view(page).locator('.pos-card').first().locator('.item-input:has(.item-title:text-is("Kategorie")) input');
  await c1.fill('Fest'); await c1.dispatchEvent('input'); await c1.blur();
  // Position 2 hinzufuegen -> Vorbelegung Grund/Kategorie pruefen
  await btn(view(page), /Position hinzu|Weitere Position|hinzufügen/i).first().click();
  const card2 = view(page).locator('.pos-card').nth(1);
  await card2.waitFor();
  const reason2 = await card2.locator('.item-input:has(.item-title:text-is("Grund")) input').inputValue();
  const cat2 = await card2.locator('.item-input:has(.item-title:text-is("Kategorie")) input').inputValue();
  assert.equal(reason2, 'Sommerfest', 'Vorbelegung Grund in Position 2');
  assert.equal(cat2, 'Fest', 'Vorbelegung Kategorie in Position 2');
  const i2 = card2.locator('.item-input:has(.item-title:text-is("Bezug")) input'); await i2.fill('Getränke'); await i2.dispatchEvent('input');
  const a2 = card2.locator('.item-input:has(.item-title:text-is("Betrag (€)")) input'); await a2.fill('5,00'); await a2.dispatchEvent('input');
  await btn(view(page), /buchen/i).last().click();
  await page.waitForFunction(() => document.querySelector('.hero-saldo')?.textContent.includes('124,10'), null, { timeout: 10000 });
  log('5 Beleg-Flow (Aus): 2 Positionen, Vorbelegung Grund/Kategorie ok, Saldo 124,10');

  // ---- 6. Saldo + Kategorien, Belegnummern, gemeinsamer Beleg --------------------
  await page.locator('.page-current .hero-saldo').waitFor();
  const catText = await page.locator('.view-main .page-current').first().innerText();
  for (const [name, sum] of [['Anna', 1250], ['Fest', 1340]]) {
    assert.ok(catText.includes(name), `Kategorie ${name} fehlt`);
    assert.ok(catText.includes(eur(sum)), `Summe ${eur(sum)} fuer ${name} fehlt`);
  }
  const token = await login(PIN);
  await waitFor(async () => (await apiCall('GET', '/sync?since=0', token)).json.entries.length === 4, 'Sync der 4 Buchungen');
  const sync0 = (await apiCall('GET', '/sync?since=0', token)).json;
  const live = sync0.entries.filter((e) => !e.deleted_at);
  assert.equal(live.length, 4, 'Server: 4 Buchungen erwartet');
  assert.ok(live.every((e) => Number.isInteger(e.number)), 'Belegnummern fehlen');
  assert.equal(new Set(live.map((e) => e.number)).size, 4, 'Belegnummern nicht eindeutig');
  const withReceipt = live.filter((e) => e.receipt_id);
  assert.equal(withReceipt.length, 2);
  assert.equal(withReceipt[0].receipt_id, withReceipt[1].receipt_id, 'Beleg nicht gemeinsam');
  const rf = await fetch(`${API}/receipts/${withReceipt[0].receipt_id}/file`, { headers: { Authorization: 'Bearer ' + token } });
  assert.equal(rf.status, 200); assert.match(rf.headers.get('content-type') || '', /^image\//);
  log('6 Saldo, "Ausgaben je Kategorie" (Anna 12,50 / Fest 13,40), Belegnummern, gemeinsamer Beleg (Datei abrufbar)');

  // ---- 7. Filter Buchungen -----------------------------------------------------
  await tab(page, 'Buchungen');
  const rowsCount = () => page.locator('#view-ledger .bk').count();
  assert.equal(await rowsCount(), 4);
  await view(page).locator('a:has(.icon-f7), a.icon-only').filter({ has: page.locator('[aria-label]') }).first().click().catch(() => {});
  await page.locator('.navbar .right a[aria-label="Filter"]').last().click();
  await page.locator('.sheet-modal.modal-in select').first().selectOption('in');
  await btn(page.locator('.sheet-modal.modal-in'), 'Fertig').click();
  await sleep(600);
  assert.equal(await rowsCount(), 1, 'Filter "Nur Einnahmen"');
  await page.locator('.navbar .right a[aria-label="Filter"]').last().click();
  await btn(page.locator('.sheet-modal.modal-in'), 'Zurücksetzen').click();
  await btn(page.locator('.sheet-modal.modal-in'), 'Fertig').click();
  await sleep(600);
  assert.equal(await rowsCount(), 4);
  const q = page.locator('#view-ledger input[type=search]').first();
  await q.fill('Kuchen'); await q.dispatchEvent('input'); await sleep(500);
  assert.equal(await rowsCount(), 1, 'Suche');
  await q.fill(''); await q.dispatchEvent('input'); await sleep(300);
  log('7 Filter (Art) + Suche in Buchungen');

  // ---- 8. Bearbeiten -----------------------------------------------------------
  await page.locator('#view-ledger .bk').filter({ visible: true }).filter({ hasText: 'Blumenstrauß' }).last().click();
  await btn(view(page), 'Bearbeiten').click();
  await fill(page, 'Betrag (€)', '13,00');
  await save(page);
  await sleep(1500);
  const edited = (await apiCall('GET', '/sync?since=0', token)).json.entries.find((e) => e.item === 'Blumenstrauß');
  assert.equal(edited.amount_cents, 1300, 'Bearbeiten nicht beim Server angekommen');
  log('8 Bearbeiten (12,50 -> 13,00) beim Server angekommen');

  // ---- 9. Loeschen -> Papierkorb -> wiederherstellen -> loeschen -> leeren -------
  await page.goto(BASE); // frischer Start, bleibt entsperrt (Token)
  await page.locator('.hero-saldo').waitFor();
  if (process.env.E2E_SHOTS) { // optional: Optik-Screenshots (Ordner), z. B. E2E_SHOTS=/pfad
    const shot = async (name, size, theme) => {
      await page.setViewportSize(size);
      await page.goto(BASE + '?theme=' + theme);
      await page.locator('.hero-saldo').first().waitFor();
      await sleep(600);
      await page.screenshot({ path: join(process.env.E2E_SHOTS, `optik-${name}.png`) });
    };
    const phone = { width: 390, height: 844 };
    await shot('ios-390', phone, 'ios');
    await page.locator('.sync-icon').first().click(); await sleep(700);
    await page.screenshot({ path: join(process.env.E2E_SHOTS, 'optik-ios-390-popover.png') });
    await shot('md-390', phone, 'md');
    await page.locator('.sync-icon').first().click(); await sleep(700);
    await page.screenshot({ path: join(process.env.E2E_SHOTS, 'optik-md-390-popover.png') });
    await shot('desktop-1280', { width: 1280, height: 800 }, 'md');
    await page.locator('a.item-link').filter({ hasText: 'Buchungen' }).first().click(); await sleep(700);
    await page.screenshot({ path: join(process.env.E2E_SHOTS, 'optik-desktop-1280-buchungen.png') });
    await page.setViewportSize(phone);
    await page.goto(BASE); await page.locator('.hero-saldo').first().waitFor(); await sleep(500);
  }
  await tab(page, 'Buchungen');
  await page.locator('#view-ledger .bk').filter({ visible: true }).filter({ hasText: 'Blumenstrauß' }).last().click();
  await btn(view(page), 'Löschen').click();
  await confirmDialog(page);
  await sleep(1500);
  const trashed = (await apiCall('GET', '/sync?since=0', token)).json.entries.find((e) => e.item === 'Blumenstrauß');
  assert.ok(trashed.deleted_at, 'Löschen nicht beim Server');
  await tab(page, 'Mehr');
  await view(page).locator('a.item-link').filter({ hasText: 'Papierkorb' }).click();
  await btn(view(page), /Wiederherstellen/).first().click();
  await sleep(1500);
  assert.equal((await apiCall('GET', '/sync?since=0', token)).json.entries.find((e) => e.item === 'Blumenstrauß').deleted_at, null, 'Wiederherstellen');
  await view(page).locator('.navbar a.back').first().click();
  await tab(page, 'Buchungen');
  await page.locator('#view-ledger .bk').filter({ visible: true }).filter({ hasText: 'Blumenstrauß' }).last().click();
  await btn(view(page), 'Löschen').click();
  await confirmDialog(page);
  await sleep(1200);
  await tab(page, 'Mehr');
  await view(page).locator('a.item-link').filter({ hasText: 'Papierkorb' }).click();
  await btn(view(page), /Endgültig löschen/).first().click();
  await confirmDialog(page);
  await sleep(1200);
  let after = (await apiCall('GET', '/sync?since=0', token)).json;
  assert.ok(!after.entries.some((e) => e.item === 'Blumenstrauß'), 'Endgültig löschen');
  // Papierkorb leeren: zweite Buchung loeschen
  await view(page).locator('.navbar a.back').first().click();
  await tab(page, 'Buchungen');
  await page.locator('#view-ledger .bk').filter({ visible: true }).filter({ hasText: 'Kuchen' }).last().click();
  await btn(view(page), 'Löschen').click();
  await confirmDialog(page);
  await sleep(1200);
  await tab(page, 'Mehr');
  await view(page).locator('a.item-link').filter({ hasText: 'Papierkorb' }).click();
  await btn(view(page), 'Papierkorb leeren').click();
  await confirmDialog(page);
  await sleep(1200);
  after = (await apiCall('GET', '/sync?since=0', token)).json;
  assert.equal(after.entries.filter((e) => e.deleted_at).length, 0, 'Papierkorb leeren');
  await view(page).locator('text=Der Papierkorb ist leer.').waitFor();
  log('9 Löschen -> Papierkorb -> wiederherstellen -> endgültig löschen -> Papierkorb leeren');
  await view(page).locator('.navbar a.back').first().click();

  // ---- 10. Kontoauszug-PDF -------------------------------------------------------
  await tab(page, 'Auszug');
  await btn(view(page), /PDF erstellen/).click();
  await sleep(1500);
  await collectTickets(page);
  const pdf = downloads.find((d) => d.url.includes('statement.pdf'));
  assert.ok(pdf, 'kein Ticket-Aufruf fuer statement.pdf');
  assert.equal(pdf.status, 200); assert.match(pdf.type, /application\/pdf/); assert.equal(pdf.head, '%PDF');
  log('10 Kontoauszug-PDF per Ticket (200, application/pdf)');

  // ---- 11. Sicherung -------------------------------------------------------------
  await tab(page, 'Mehr');
  await view(page).locator('a.item-link').filter({ hasText: 'Datensicherung' }).click();
  await btn(view(page), 'Sicherung herunterladen').click();
  await sleep(1500);
  await collectTickets(page);
  const bk = downloads.find((d) => d.url.includes('ticket=') && !d.url.includes('statement.pdf'));
  assert.ok(bk, 'kein Ticket-Aufruf fuer die Sicherung');
  assert.equal(bk.status, 200); assert.match(bk.type, /zip/);
  log('11 Sicherung per Ticket (200, ' + bk.type + ')');
  await view(page).locator('.navbar a.back').first().click();

  // ---- 12. Diagnose ---------------------------------------------------------------
  await view(page).locator('a.item-link').filter({ hasText: 'Diagnose' }).click();
  await btn(view(page), 'Alles').click();
  await view(page).locator('.log-list li').first().waitFor({ timeout: 8000 });
  assert.ok(await view(page).locator('.log-list li').count() > 0, 'Diagnose zeigt keine Logs');
  log('12 Diagnose-Seite zeigt Protokolleinträge');
  await view(page).locator('.navbar a.back').first().click();

  // ---- 13. Service Worker -----------------------------------------------------------
  const sw = await page.evaluate(async () => {
    const reg = await navigator.serviceWorker.getRegistration();
    await navigator.serviceWorker.ready;
    return reg ? { scope: reg.scope, active: !!reg.active } : null;
  });
  assert.ok(sw && sw.active, 'Service Worker nicht aktiv');
  log('13 Service Worker registriert (Scope ' + sw.scope.replace(ORIGIN, '') + ')');

  // ---- 14. Offline -> Buchung -> online -> Sync ---------------------------------------
  await tab(page, 'Übersicht');
  await ctx.setOffline(true);
  await page.evaluate(() => window.dispatchEvent(new Event('offline')));
  await sleep(500);
  await newBooking(page, 'Einnahme');
  await fill(page, 'Betrag (€)', '7,00');
  await fill(page, 'Verwendungszweck', 'Offline-Spende');
  await save(page);
  await sleep(500);
  assert.ok(!(await apiCall('GET', '/sync?since=0', token)).json.entries.some((e) => e.purpose === 'Offline-Spende'), 'darf offline noch nicht am Server sein');
  await ctx.setOffline(false);
  await page.evaluate(() => window.dispatchEvent(new Event('online')));
  let synced = false;
  for (let i = 0; i < 30 && !synced; i++) {
    await sleep(500);
    synced = (await apiCall('GET', '/sync?since=0', token)).json.entries.some((e) => e.purpose === 'Offline-Spende');
  }
  assert.ok(synced, 'Offline-Buchung wurde nach dem Wiederverbinden nicht synchronisiert');
  log('14 Offline-Buchung nach Wiederverbinden synchronisiert');

  // ---- 15. PIN aendern + neue PIN --------------------------------------------------------
  await tab(page, 'Mehr');
  await view(page).locator('a.item-link').filter({ hasText: 'PIN ändern' }).click();
  const pop = page.locator('.popup.modal-in');
  const pins = pop.locator('input[type=password]');
  await pins.nth(0).fill(PIN); await pins.nth(0).dispatchEvent('input');
  await pins.nth(1).fill(NEW_PIN); await pins.nth(1).dispatchEvent('input');
  await pins.nth(2).fill(NEW_PIN); await pins.nth(2).dispatchEvent('input');
  await btn(pop, 'Speichern').click();
  await sleep(1000);
  assert.equal((await apiCall('POST', '/auth/login', null, { pin: PIN })).status, 401, 'alte PIN gilt noch');
  const page2 = await (await browser.newContext({ viewport: { width: 1280, height: 800 } })).newPage();
  watch(page2, 'pin');
  await page2.goto(BASE);
  await pressPin(page2, NEW_PIN);
  await page2.locator('.hero-saldo').first().waitFor();
  log('15 PIN geändert; neue PIN meldet an (Desktop 1280x800), alte abgewiesen');
  await page2.context().close();

  // ---- 16. Lesen ohne PIN ---------------------------------------------------------------
  const tk = await login(NEW_PIN);
  await view(page).locator('.item-title:text-is("Lesen ohne PIN")').first().scrollIntoViewIfNeeded();
  await view(page).locator('li:has(.item-title:text-is("Lesen ohne PIN")) .toggle').click();
  await sleep(1000);
  assert.equal((await apiCall('GET', '/settings', tk)).json.read_without_pin, true);
  const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  const p3 = await ctx2.newPage();
  watch(p3, 'readonly');
  await p3.goto(BASE);
  await p3.locator('.hero-saldo').first().waitFor({ timeout: 10000 });
  assert.ok(await p3.locator('.lock-screen').count() === 0, 'PIN-Sperre trotz "Lesen ohne PIN"');
  assert.ok(await p3.locator('.hero-saldo').first().innerText().then((t) => /\d/.test(t)));
  assert.equal(await p3.locator('.global-fab').count(), 0, 'FAB sichtbar ohne PIN');
  const w = await apiCall('POST', '/sync', null, { entries: [] });
  assert.equal(w.status, 403); assert.equal(w.json.error.code, 'pin_required');
  assert.equal((await apiCall('GET', '/sync?since=0', null)).status, 200);
  assert.equal((await apiCall('GET', '/logs', null)).status, 401, 'Logs ohne Token');
  log('16 "Lesen ohne PIN": anderer Kontext sieht Daten, kein FAB, Schreiben -> 403 pin_required');
  await ctx2.close();

  // ---- Konsolenfehler --------------------------------------------------------------------
  const real = consoleErrors.filter((e) => !/net::ERR_INTERNET_DISCONNECTED|Failed to load resource.*(401|403)|ERR_FAILED/.test(e));
  assert.deepEqual(real, [], 'Konsolenfehler:\n' + real.join('\n'));
  log('17 keine Konsolenfehler (' + consoleErrors.length + ' erwartbare ignoriert)');
}

try {
  await main();
  console.log('== UI-E2E erfolgreich');
} catch (e) {
  console.error('FEHLER: ' + (e && e.stack || e));
  if (consoleErrors.length) console.error('Konsole: ' + consoleErrors.join('\n'));
  if (lastPage) {
    try {
      const shot = join(tmpdir(), 'quitt-e2e-ui-fehler.png');
      await lastPage.screenshot({ path: shot });
      console.error('Screenshot: ' + shot);
      if (process.env.E2E_DEBUG) console.error('HTML: ' + (await lastPage.locator('.view').evaluateAll((v) => v.map((x) => x.className + ' >> ' + [...x.querySelectorAll('.page')].map((p) => p.className).join(' | ')))).join('\n') + '\n' + (await lastPage.locator('.page-current:visible .item-input').first().evaluate((e) => e.outerHTML).catch(() => '')));
      console.error('Sichtbarer Text: ' + (await lastPage.locator('body').innerText()).replace(/\s+/g, ' ').slice(0, 800));
    } catch { /* egal */ }
  }
  process.exitCode = 1;
} finally {
  await cleanup();
}
