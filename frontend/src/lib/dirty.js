// Zentraler Merker fuer ungesicherte Eingaben (Leitlinie PWA, Punkt 4).
// Ansichten melden sich mit einem Schluessel an (setDirty(key, true)) und wieder ab.
// Der Update-Mechanismus laedt nie neu, solange isDirty() true ist.

const keys = new Set();
const listeners = new Set();

export function setDirty(key, value) {
  const before = keys.size;
  if (value) keys.add(key); else keys.delete(key);
  if (before !== keys.size) listeners.forEach((fn) => fn(isDirty()));
}

function inputFocused() {
  if (typeof document === 'undefined') return false;
  const el = document.activeElement;
  if (!el) return false;
  return el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName);
}

export function isDirty() {
  return keys.size > 0 || inputFocused();
}

/** Benachrichtigung bei Aenderung; liefert Abmeldefunktion. */
export function onDirtyChange(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

export function _resetDirty() { keys.clear(); }
