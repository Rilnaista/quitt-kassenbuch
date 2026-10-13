<?php

declare(strict_types=1);

namespace Quitt\Service;

/**
 * Saldo- und Seitenumbruchlogik des Kontoauszugs (rein rechnerisch, ohne PDF testbar).
 *
 * Jede Zeile (item) hat einen vorzeichenbehafteten Betrag `amount` (Einnahme +, Ausgabe -, in Cent)
 * und eine Hoehe `height` (mm). Die Seiten haben je eine Nutzhoehe fuer Buchungszeilen
 * (Seite 1 weniger, da Kopf + Anfangssaldo; ab Seite 2 Kopf + Uebertrag).
 * Ergebnis je Seite: carry (Saldo vor der ersten Zeile = Anfangssaldo bzw. Uebertrag),
 * rows (item + balance nach der Zeile), subtotal (Zwischensaldo = Saldo nach letzter Zeile).
 * Invariante: carry(Seite n+1) == subtotal(Seite n); closing == opening + Summe(amount).
 */
final class StatementPaginator
{
    /** Layout in mm (A4 hoch, siehe StatementPdf; Aenderungen immer an beiden Stellen). */
    public const LINE_HEIGHT = 4.2;
    public const ROW_GAP = 0.8;
    public const FIRST_PAGE_CAPACITY = 215.0;
    public const NEXT_PAGE_CAPACITY = 233.0;
    public const MAX_TEXT_LINES = 3;

    /**
     * @param list<array<string, mixed>> $items je mit 'amount' (int) und 'height' (float mm)
     * @return array{pages: list<array{carry: int, rows: list<array<string, mixed>>, subtotal: int}>, closing: int}
     */
    public function paginate(
        int $opening,
        array $items,
        float $firstCapacity = self::FIRST_PAGE_CAPACITY,
        float $nextCapacity = self::NEXT_PAGE_CAPACITY,
    ): array {
        $pages = [];
        $balance = $opening;
        $page = ['carry' => $balance, 'rows' => [], 'subtotal' => $balance];
        $free = $firstCapacity;
        foreach ($items as $item) {
            $height = (float) $item['height'];
            if ($page['rows'] !== [] && $height > $free + 1e-9) {
                $pages[] = $page;
                $page = ['carry' => $balance, 'rows' => [], 'subtotal' => $balance];
                $free = $nextCapacity;
            }
            $balance += (int) $item['amount'];
            $item['balance'] = $balance;
            $page['rows'][] = $item;
            $page['subtotal'] = $balance;
            $free -= $height;
        }
        $pages[] = $page;
        return ['pages' => $pages, 'closing' => $balance];
    }

    /** Hoehe einer Buchungszeile mit $lines Textzeilen (mm). */
    public static function rowHeight(int $lines): float
    {
        return max(1, $lines) * self::LINE_HEIGHT + self::ROW_GAP;
    }

    /** Cent -> "1.234,56" (negativ mit fuehrendem Minus). */
    public static function formatCents(int $cents): string
    {
        $abs = abs($cents);
        $int = number_format(intdiv($abs, 100), 0, '', '.');
        return ($cents < 0 ? '-' : '') . $int . ',' . sprintf('%02d', $abs % 100);
    }

    /**
     * Bricht Text auf Spaltenbreite (Zeichen, Monospace) um; hoechstens $maxLines Zeilen,
     * ueberlanger Rest wird mit "…" gekuerzt. Zu lange Woerter werden hart getrennt.
     *
     * @return list<string>
     */
    public static function wrap(string $text, int $width, int $maxLines = self::MAX_TEXT_LINES): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return [''];
        }
        $lines = [];
        $current = '';
        foreach (explode(' ', $text) as $word) {
            while (mb_strlen($word) > $width) {
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                $lines[] = mb_substr($word, 0, $width);
                $word = mb_substr($word, $width);
            }
            if ($current === '') {
                $current = $word;
            } elseif (mb_strlen($current) + 1 + mb_strlen($word) <= $width) {
                $current .= ' ' . $word;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $last = rtrim($lines[$maxLines - 1]);
            $lines[$maxLines - 1] = mb_strlen($last) >= $width ? mb_substr($last, 0, $width - 1) . '…' : $last . '…';
        }
        return $lines;
    }
}
