// Demo-Hinweis (REQ-080): Quitt laeuft oeffentlich als Demo unter demo.quitt.cloud
// und wird dort stuendlich zurueckgesetzt. Ausserhalb dieses Hosts kein Hinweis.

export const DEMO_HOST = 'demo.quitt.cloud';

/** true genau fuer den Demo-Host (Gross/Klein egal, optionaler abschliessender Punkt). */
export function isDemoHost(hostname = typeof location !== 'undefined' ? location.hostname : '') {
  return String(hostname).toLowerCase().replace(/\.$/, '') === DEMO_HOST;
}

/** Hinweis aktiv: Demo-Host ODER (nur Entwicklung) ?demo=1 in der URL. */
export function demoActive() {
  if (isDemoHost()) return true;
  if (!import.meta.env.DEV || typeof location === 'undefined') return false;
  return new URLSearchParams(location.search).get('demo') === '1';
}
