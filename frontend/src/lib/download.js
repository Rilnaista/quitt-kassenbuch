// Downloads per Einmal-Ticket oeffnen. Auf iOS (Homescreen-App) ist ein sofort geoeffnetes Fenster
// bzw. das Teilen-Blatt der verlaessliche Weg; Anker mit download-Attribut geht dort oft nicht.
export const isIos = () => /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

/** Ticket-URL oeffnen. `win` = vorab (synchron im Klick) geoeffnetes Fenster, sonst Anker/Navigation. */
export function openUrl(url, { win = null, download = true, newTab = false } = {}) {
  if (win && !win.closed) { win.location.href = url; return; }
  if (isIos()) { location.href = url; return; }
  const a = document.createElement('a');
  a.href = url;
  if (download) a.download = '';
  if (newTab) a.target = '_blank';
  a.rel = 'noopener';
  document.body.appendChild(a);
  a.click();
  a.remove();
}

/** Im Klick-Handler VOR dem ersten await aufrufen (Popup-Blocker); nur auf iOS noetig. */
export function preopenWindow() {
  if (!isIos()) return null;
  try { return window.open('', '_blank'); } catch (e) { return null; }
}

/** Datei mit dem System-Teilen-Blatt teilen, wenn der Browser das kann; sonst false. */
export async function shareFile(blob, filename, mime) {
  if (!navigator.canShare || !navigator.share) return false;
  const file = new File([blob], filename, { type: mime });
  if (!navigator.canShare({ files: [file] })) return false;
  try {
    await navigator.share({ files: [file], title: filename });
    return true;
  } catch (e) {
    if (e && e.name === 'AbortError') return true; // Nutzer hat abgebrochen
    return false;
  }
}

export function fmtBytes(n) {
  if (!Number.isFinite(n)) return '';
  if (n < 1024) return `${n} B`;
  if (n < 1048576) return `${(n / 1024).toFixed(0)} KB`;
  return `${(n / 1048576).toFixed(1).replace('.', ',')} MB`;
}
