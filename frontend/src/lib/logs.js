// Protokoll-Zeilen: neueste oben (unabhaengig von der Reihenfolge der Antwort).
export function sortNewest(rows) {
  return [...(rows || [])].sort((a, b) => (Date.parse(b.ts) || 0) - (Date.parse(a.ts) || 0));
}
