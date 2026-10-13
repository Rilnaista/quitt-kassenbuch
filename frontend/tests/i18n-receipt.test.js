// Rechtschreibung (echte Umlaute) in den Texten des Beleg-Flows / der Belegerkennung.
import { describe, it, expect } from 'vitest';
import de from '../src/i18n/receipt.de.json';
import { t } from '../src/i18n/index.js';

const BAD = /\b[A-Za-z]*(ae|oe|ue)[a-z]*|\b(gross|groess|schliess|strasse|ausser)[a-z]*/gi;
const ALLOW = /(neue|erneut|aktuell|manuell|queue|ollama|gemini|anthropic|openai|mistral)/i;
const strings = (o) => Object.values(o).flatMap((v) => (typeof v === 'string' ? [v] : strings(v)));

describe('receipt.de.json', () => {
  it('keine ae/oe/ue/ss-Ersatzschreibweisen', () => {
    const bad = strings(de).flatMap((s) => (s.match(BAD) || []).filter((m) => !ALLOW.test(m)));
    expect(bad).toEqual([]);
  });
  it('ist im Woerterbuch eingebunden', () => {
    expect(t('flow.title')).toBe('Beleg scannen');
    expect(t('recog.mode.local')).toContain('Gerät');
  });
});
