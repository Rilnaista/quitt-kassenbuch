// Erzeugt alle App-Icons aus dem Quitt-Logo (src/assets/logo.svg).
// Aufruf: npm run icons  (Ergebnis liegt eingecheckt in public/app-icons/, Build braucht kein resvg).
import { Resvg } from '@resvg/resvg-js';
import { mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const out = fileURLToPath(new URL('../public/app-icons/', import.meta.url));
mkdirSync(out, { recursive: true });

const GREEN = '#1F8A5B';
const glyph = '<g fill="none" stroke="#fff" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"><path d="M38.5 48.1A20 20 0 1 1 47.3 20"/><path d="M23 32l11 11 22-29"/></g>';
// any: abgerundet wie das Favicon; apple: vollflaechig (iOS rundet selbst);
// maskable: vollflaechig, Zeichen auf ~70 % verkleinert (Sicherheitszone 80 %).
const svg = {
  any: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="${GREEN}"/>${glyph}</svg>`,
  full: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="${GREEN}"/>${glyph}</svg>`,
  maskable: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="${GREEN}"/><g transform="translate(32 32) scale(0.7) translate(-32 -32)">${glyph}</g></svg>`,
};

const jobs = [
  ['favicon-32.png', 'any', 32],
  ['icon-192.png', 'any', 192],
  ['icon-512.png', 'any', 512],
  ['apple-touch-icon-180.png', 'full', 180],
  ['maskable-192.png', 'maskable', 192],
  ['maskable-512.png', 'maskable', 512],
];
for (const [name, kind, size] of jobs) {
  const png = new Resvg(svg[kind], { fitTo: { mode: 'width', value: size } }).render().asPng();
  writeFileSync(out + name, png);
  console.log('app-icons/' + name, size + 'px');
}
