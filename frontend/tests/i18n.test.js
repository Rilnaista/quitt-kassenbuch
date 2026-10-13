// Leitlinie "Rechtschreibung": nutzersichtbare Texte mit echten Umlauten/ß.
// Prueft de.json sowie die statischen Seiten (offline.html, Manifest) auf ae/oe/ue/ss-Ersatzschreibweisen.
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import de from '../src/i18n/de.json';
import deTools from '../src/i18n/tools.de.json';
import deHelp from '../src/i18n/help.de.json';

// Woerter, in denen ae/oe/ue legitim vorkommen
const ALLOW = /(aue|eue|quellcode|neue|erneut|aktuell|manuell|eventuell|quelle|steuer|teuer|dauer|feuer|abenteuer|bauer|mauer|trauer|treue|queue|israel|michael|poet|koeffiz|virtuell|individuell|sexuell|duell|zuerst|mistral|ollama|gemini|anthropic|openai)/i;
const UML = /[A-Za-z]*(ae|oe|ue|Ae|Oe|Ue)[A-Za-z]*/g;
// Typische falsche ss-Schreibungen (statt ß)
const SS = /\b(gross|groess|strasse|gruss|grues|schliess|heiss|weiss|fuss|spass|aeusser|ausser|draussen|gemaess|massnahm|regelmaessig|gleichmaessig|bloss|giess|fliess|geniess|stoss|reiss|beiss|schluss)[a-z]*/i;

function strings(obj, path = []) {
  return Object.entries(obj).flatMap(([k, v]) => (typeof v === 'string' ? [[path.concat(k).join('.'), v]] : strings(v, path.concat(k))));
}

function offenders(text) {
  const bad = [];
  for (const m of text.matchAll(UML)) if (!ALLOW.test(m[0])) bad.push(m[0]);
  const s = text.match(SS);
  if (s) bad.push(s[0]);
  return bad;
}

describe('Umlaute in nutzersichtbaren Texten', () => {
  it('de.json enthaelt keine ae/oe/ue/ss-Ersatzschreibweisen', () => {
    const bad = strings(de).map(([k, v]) => [k, offenders(v)]).filter(([, b]) => b.length);
    expect(bad).toEqual([]);
  });
  it.each([['tools.de.json', deTools], ['help.de.json', deHelp]])('%s enthaelt keine ae/oe/ue/ss-Ersatzschreibweisen', (_n, dict) => {
    const bad = strings(dict).map(([k, v]) => [k, offenders(v)]).filter(([, b]) => b.length);
    expect(bad).toEqual([]);
  });
  it.each(['tools.de.json', 'help.de.json'])('%s ist UTF-8 ohne BOM mit Umlauten', (f) => {
    const raw = readFileSync(new URL('../src/i18n/' + f, import.meta.url));
    expect(raw[0]).not.toBe(0xef);
    expect(raw.toString('utf8')).toMatch(/[äöüÄÖÜß]/);
  });
  it('de.json enthaelt echte Umlaute (Datei ist UTF-8 ohne BOM)', () => {
    const raw = readFileSync(new URL('../src/i18n/de.json', import.meta.url));
    expect(raw[0]).not.toBe(0xef);
    expect(raw.toString('utf8')).toMatch(/[äöüÄÖÜß]/);
  });
  it.each(['public/offline.html', 'public/manifest.webmanifest', 'index.html'])('%s ohne Ersatzschreibweisen', (f) => {
    const text = readFileSync(new URL('../' + f, import.meta.url), 'utf8')
      .replace(/<style[\s\S]*?<\/style>/g, '')
      .replace(/<[^>]+>/g, ' ');
    expect(offenders(text)).toEqual([]);
  });
  it('Test erkennt Ersatzschreibweisen tatsaechlich', () => {
    expect(offenders('Zurueck zur Uebersicht')).toEqual(['Zurueck', 'Uebersicht']);
    expect(offenders('Schliessen')).toEqual(['Schliessen']);
    expect(offenders('Neue Version, erneut prüfen')).toEqual([]);
  });
});
