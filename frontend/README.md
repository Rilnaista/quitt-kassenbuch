# Quitt – Frontend

Vue 3 + Framework7 (Theme auto) + Pinia + IndexedDB (`idb`). Konventionen: `.claude/docs/conventions-frontend.md`,
Vertrag: `.claude/docs/api-contract.md`.

```
npm install
npm run dev      # Vite, Proxy /api -> http://localhost:8000
npm test         # Vitest
npm run build    # dist/ (relativ, base './'), inkl. sw.js mit Build-Hash
npm run icons    # App-Icons aus src/assets/logo.svg neu erzeugen (Ergebnis eingecheckt)
```

## Architektur (Kurzfassung)
- `src/api/` – einziger Netzzugriff. API-Basis = `<Ort von index.html>/api/v1` (nie fest verdrahtet).
  Fehler -> `ApiError {status, code, message, fields, retryAfter}`; Netzfehler = `code: 'network'`.
  401 mit Token -> Token weg + `onAuthProblem`. Downloads per Einmal-Ticket (`createDownload`).
- `src/db/` – IndexedDB ist Quelle der Wahrheit der UI. Stores: entries, receipts, blobs, outbox, uploads, meta.
  Schema-Aenderung nur ueber `DB_VERSION` + Upgrade-Schritt. `repo.js` = lokale Schreiboperationen
  (immer entries + outbox atomar, `updated_at` = jetzt -> Last-Write-Wins).
- `src/sync/engine.js` – Uploads -> Outbox (POST /sync, 500er-Pakete) -> Pull (GET /sync?since=cursor).
  Cursor nur aus GET. conflicts -> Server-Stand; 422 je id -> als abgelehnt markiert, Rest geht weiter;
  purged -> lokal entfernen. Single-flight. Trigger (stores/sync.js): Start, online, sichtbar, nach Aenderung.
- `src/stores/` – Pinia je Domaene: session (Zugriff), data (Buchungen + abgeleitete Werte), sync, settings
  (Server, gecacht), appearance (geraete-lokal), ui.
- Zugriffsmodell: Geraet meldet sich per PIN an und behaelt das Token (gleitend 365 Tage). Ohne Token:
  gesperrt bzw. lesend (wenn "Lesen ohne PIN"). "Jetzt sperren" = Abmelden.
- `src/lib/` – reine Logik: money (Cent), ledger (Saldo/Summen/Filter), image (Verkleinerung), dirty (Merker
  fuer ungesicherte Eingaben), pwa (Update-Mechanismus).
- PWA: `sw/sw-template.js` -> `dist/sw.js` (Cache-Name = SHA-256 ueber alle Build-Dateien). API wird nie
  gecacht. Updates: Start/sichtbar/30 min; nie bei ungesicherten Eingaben; Schleifenschutz ueber gemerkte Build-ID.
- Platzhalter (`pages/Placeholder.vue`): nur noch, was der Beleg-Flow-Auftrag ersetzt (Beleg, Belegerkennung).
- Sprachdateien: `i18n/de.json` + `tools.de.json` (Auszug/Sicherung/Update/Diagnose) + `help.de.json` (Hilfe, im Bundle -> offline); `i18n/index.js` fuehrt sie zusammen.
- Hilfe: `?`-Knopf (`HelpButton topic=..`) -> `HelpPopup` (Kontext) -> `/hilfe/` (Themen). Update-Hinweis: `stores/update.js` + `lib/updateCheck.js` (1x/Tag, still).
