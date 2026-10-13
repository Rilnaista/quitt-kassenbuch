// Frontend-Fehler gedrosselt an POST /logs/client (Mindestabstand, Obergrenze, keine Dubletten).
export function createReporter(send, { minGap = 5000, maxTotal = 20, dupeMs = 60000, now = () => Date.now() } = {}) {
  let last = -Infinity;
  let total = 0;
  const seen = new Map();
  return function report(message, context = {}) {
    const msg = String(message == null ? 'unbekannter Fehler' : message).slice(0, 500);
    const t = now();
    if (total >= maxTotal || t - last < minGap) return false;
    if (seen.has(msg) && t - seen.get(msg) < dupeMs) return false;
    last = t;
    total++;
    seen.set(msg, t);
    try { Promise.resolve(send('error', msg, context)).catch(() => {}); } catch (e) { /* still */ }
    return true;
  };
}

export function installClientLogging(app, send) {
  const report = createReporter(send);
  app.config.errorHandler = (err, _vm, info) => {
    console.error(err);
    report(err && err.message, { info, stack: err && err.stack && err.stack.slice(0, 2000) });
  };
  window.addEventListener('error', (ev) => {
    report(ev.message, { type: 'onerror', source: ev.filename, line: ev.lineno, col: ev.colno, stack: ev.error && ev.error.stack && ev.error.stack.slice(0, 2000) });
  });
  window.addEventListener('unhandledrejection', (ev) => {
    const r = ev.reason;
    if (r && r.name === 'ApiError') return; // erwartete API-Fehler behandelt die UI
    report(r && r.message ? r.message : r, { type: 'unhandledrejection' });
  });
  return report;
}
