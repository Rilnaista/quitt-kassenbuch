<?php

declare(strict_types=1);

namespace Quitt\Recognition;

/** Prompt fuer die Belegerkennung (deutsche Kassenbons/Rechnungen). Nicht nutzersichtbar. */
final class Prompt
{
    public const RECEIPT = <<<'TXT'
Du liest einen Kassenbon oder eine Rechnung (meist deutsch) aus einem Foto aus.
Antworte AUSSCHLIESSLICH mit einem JSON-Objekt, ohne Erklaerung und ohne Markdown, in dieser Form:
{"date": "YYYY-MM-DD" oder null, "positions": [{"item": "Bezeichnung", "amount_cents": 249}], "total_cents": 1999 oder null}

Regeln:
- date: Belegdatum (nicht Druck-/Zahlungszeit anderer Art), als YYYY-MM-DD; deutsche Schreibweise TT.MM.JJJJ umwandeln. Unklar -> null.
- positions: eine Eintrag je gekaufter Position, in der Reihenfolge des Belegs. item = lesbare Bezeichnung (Abkuerzungen duerfen bleiben).
- amount_cents: Endpreis der Position in GANZEN CENT als Ganzzahl (2,49 EUR = 249), nach Abzug von Rabatten, die direkt zu dieser Position gehoeren (Rabatt-/Aktionszeile unter der Position in die Position einrechnen, keine eigene Position).
  Bei Menge x Einzelpreis den Gesamtpreis der Position nehmen.
- Pfand: Pfandzeilen als eigene Position (positiv); Pfandrueckgabe/Leergut-Gutschrift als eigene Position mit NEGATIVEM Betrag.
- Allgemeine Rabatte auf den ganzen Einkauf (z. B. Gutschein, Bonus) als eigene Position mit negativem Betrag.
- Keine Zeilen fuer Zwischensumme, Gesamtsumme, MwSt, Steuer, Gegeben, Rueckgeld, Kartenzahlung, Punkte.
- total_cents: zu zahlende Gesamtsumme in Cent. Nicht lesbar -> null.
- Nichts erfinden: nicht Lesbares weglassen bzw. null setzen. Keine Kommas als Dezimaltrenner in Zahlen ausgeben.
TXT;

    public const PROBE = 'Antworte nur mit dem Wort OK.';
}
