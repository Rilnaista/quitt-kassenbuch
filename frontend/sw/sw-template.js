/* Quitt Service Worker – wird beim Build aus sw/sw-template.js erzeugt (vite.config.js).
 * Cache-Name, Build-ID und Precache-Liste setzt der Build (Platzhalter unten); NIE von Hand pflegen.
 *  - Precache: alle statischen Build-Dateien (inkl. index.html – statisch, ohne Nonce/Sitzungsbezug).
 *  - API (/api/) wird NIE abgefangen oder zwischengespeichert.
 *  - Navigation: Netz zuerst (no-store); offline -> index.html aus dem Cache, sonst offline.html.
 *  - Aktivierung einer neuen Fassung nur auf Anweisung der App (SKIP_WAITING), ausser beim Erstinstallieren.
 */
const CACHE = '__CACHE_NAME__';
const BUILD = '__BUILD_ID__';
const PRECACHE = __PRECACHE__;
const SCOPE = self.registration.scope;
const abs = (p) => new URL(p, SCOPE).href;

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE);
    await cache.addAll(PRECACHE.map((p) => new Request(abs(p), { cache: 'reload' })));
    if (!self.registration.active) await self.skipWaiting(); // Erstinstallation: sofort aktiv
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)));
    await self.clients.claim();
  })());
});

self.addEventListener('message', (event) => {
  const d = event.data || {};
  if (d.type === 'SKIP_WAITING') self.skipWaiting();
  else if (d.type === 'GET_BUILD' && event.ports[0]) event.ports[0].postMessage({ build: BUILD });
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (!url.href.startsWith(SCOPE)) return;
  if (url.href.slice(SCOPE.length).startsWith('api/') || url.pathname.includes('/api/')) return; // API nie anfassen

  if (req.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        return await fetch(req, { cache: 'no-store' });
      } catch (e) {
        const cache = await caches.open(CACHE);
        return (await cache.match(abs('index.html'))) || (await cache.match(abs('offline.html'))) || Response.error();
      }
    })());
    return;
  }

  event.respondWith((async () => {
    const cache = await caches.open(CACHE);
    const hit = await cache.match(req, { ignoreSearch: true });
    if (hit) return hit;
    const res = await fetch(req);
    // Lokale Texterkennung (ocr/): grosse Dateien werden beim ersten Gebrauch gespeichert -> danach auch offline
    if (res.ok && url.href.slice(SCOPE.length).startsWith('ocr/')) await cache.put(req, res.clone());
    return res;
  })());
});
