// Mini-Router fuer gemocktes fetch: routes['GET /sync'] = (req) => body | Response
import { vi } from 'vitest';

export const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
export const apiError = (status, code, extra = {}) => json({ error: { code, message: 'Fehler ' + code, ...extra } }, status);

export function mockFetch(routes) {
  const calls = [];
  const fn = vi.fn(async (url, init = {}) => {
    const u = new URL(url);
    const path = u.pathname.replace(/^.*\/api\/v1/, '');
    const method = init.method || 'GET';
    const key = `${method} ${path}`;
    const body = init.body && typeof init.body === 'string' ? JSON.parse(init.body) : init.body;
    const call = { method, path, query: Object.fromEntries(u.searchParams), headers: init.headers || {}, body };
    calls.push(call);
    const h = routes[key] || Object.entries(routes).find(([k]) => k.endsWith('*') && key.startsWith(k.slice(0, -1)))?.[1];
    if (!h) throw new Error('unexpected fetch ' + key);
    const r = await h(call);
    return r instanceof Response ? r : json(r);
  });
  globalThis.fetch = fn;
  return calls;
}
