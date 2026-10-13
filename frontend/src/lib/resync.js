// Nach Wiederherstellung einer Sicherung: lokalen Store leeren, Cursor weg -> Voll-Sync (since=0).
import { clearAll } from '../db/index.js';

export async function fullResync({ data, sync }) {
  await clearAll(); // inkl. meta.cursor -> naechster Pull startet bei 0
  await data.load();
  await sync.syncNow();
}
