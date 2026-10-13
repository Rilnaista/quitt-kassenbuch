<?php

declare(strict_types=1);

namespace Quitt\Recognition;

/** Normalisiert die Modellantwort auf {date, positions[{item, amount_cents}], total_cents}. */
final class ResultParser
{
    private const MAX_POSITIONS = 200;
    private const MAX_ITEM_LEN = 120;

    /**
     * @return array{date: ?string, positions: list<array{item: string, amount_cents: int}>, total_cents: ?int}
     * @throws ProviderException unreadable, wenn kein JSON-Objekt gefunden wird
     */
    public static function parse(string $text): array
    {
        $data = self::decode($text);
        if ($data === null) {
            throw new ProviderException('unreadable');
        }
        $positions = [];
        $list = $data['positions'] ?? $data['items'] ?? $data['positionen'] ?? [];
        foreach (is_array($list) ? $list : [] as $p) {
            if (!is_array($p)) {
                continue;
            }
            $name = $p['item'] ?? $p['name'] ?? $p['description'] ?? $p['bezeichnung'] ?? null;
            $cents = self::cents($p, ['amount_cents', 'cents'], ['amount', 'price', 'preis', 'betrag', 'total']);
            if (!is_string($name) || trim($name) === '' || $cents === null || $cents === 0) {
                continue;
            }
            $positions[] = ['item' => mb_substr(trim(preg_replace('/\s+/u', ' ', $name)), 0, self::MAX_ITEM_LEN), 'amount_cents' => $cents];
            if (count($positions) >= self::MAX_POSITIONS) {
                break;
            }
        }
        $total = self::cents($data, ['total_cents'], ['total', 'sum', 'gesamtsumme', 'gesamt']);
        return [
            'date' => self::date($data['date'] ?? $data['datum'] ?? null),
            'positions' => $positions,
            'total_cents' => $total !== null && $total > 0 ? $total : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function decode(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $text, $m)) {
            $text = trim($m[1]);
        }
        $try = json_decode($text, true);
        if (!is_array($try)) {
            $a = strpos($text, '{');
            $b = strrpos($text, '}');
            $try = ($a !== false && $b !== false && $b > $a) ? json_decode(substr($text, $a, $b - $a + 1), true) : null;
        }
        return is_array($try) && !array_is_list($try) ? $try : null;
    }

    /**
     * @param list<string> $centKeys Werte sind (ggf.) Cent
     * @param list<string> $euroKeys Werte sind Euro
     */
    private static function cents(array $src, array $centKeys, array $euroKeys): ?int
    {
        foreach ($centKeys as $k) {
            if (isset($src[$k]) && $src[$k] !== '') {
                $v = $src[$k];
                // ganze Zahl = Cent; Dezimalwert/Text mit Dezimaltrenner = irrtuemlich Euro
                if (is_int($v)) {
                    return $v;
                }
                if (is_float($v)) {
                    return floor($v) === $v ? (int) $v : (int) round($v * 100);
                }
                if (is_string($v)) {
                    $n = self::number($v);
                    if ($n === null) {
                        return null;
                    }
                    $hasDecimal = (bool) preg_match('/[.,]\d{1,2}\s*\D*$/u', $v) && !preg_match('/^\s*-?\d+\s*$/', $v);
                    return $hasDecimal ? (int) round($n * 100) : (int) round($n);
                }
            }
        }
        foreach ($euroKeys as $k) {
            if (isset($src[$k]) && $src[$k] !== '') {
                $n = is_int($src[$k]) || is_float($src[$k]) ? (float) $src[$k] : (is_string($src[$k]) ? self::number($src[$k]) : null);
                return $n === null ? null : (int) round($n * 100);
            }
        }
        return null;
    }

    /** "1.234,56 €", "2,49", "-0.50", "3.-" -> float */
    private static function number(string $s): ?float
    {
        $s = preg_replace('/[^\d.,\-+]/u', '', str_replace(['−', '–'], '-', $s));
        if ($s === '' || $s === null) {
            return null;
        }
        $neg = str_starts_with($s, '-') || str_ends_with($s, '-');
        $s = str_replace(['-', '+'], '', $s);
        $comma = strrpos($s, ',');
        $dot = strrpos($s, '.');
        if ($comma !== false && $dot !== false) {
            $dec = max($comma, $dot);
            $s = str_replace(['.', ','], '', substr($s, 0, $dec)) . '.' . substr($s, $dec + 1);
        } elseif ($comma !== false) {
            $s = substr_count($s, ',') > 1 ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        } elseif ($dot !== false && (substr_count($s, '.') > 1 || preg_match('/^\d{1,3}\.\d{3}$/', $s))) {
            $s = str_replace('.', '', $s); // Tausenderpunkt
        }
        if (!is_numeric($s)) {
            return null;
        }
        return $neg ? -(float) $s : (float) $s;
    }

    private static function date(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        if (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/(\d{1,2})\.\s?(\d{1,2})\.\s?(\d{4}|\d{2})\b/', $v, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($y < 100) {
                $y += 2000;
            }
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) && $y >= 2000 ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }
}
