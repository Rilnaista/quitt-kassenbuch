<?php

declare(strict_types=1);

namespace Quitt\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Zeitstempel-Helfer: einheitliches ISO-8601-UTC-Format mit Millisekunden. */
final class Time
{
    private const TS_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:?\d{2})$/';

    public static function iso(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Normalisiert einen ISO-8601-Zeitstempel nach UTC (ms); null bei ungueltigem Wert. */
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match(self::TS_PATTERN, $value)) {
            return null;
        }
        $datePart = substr($value, 0, 10);
        [$y, $m, $d] = array_map('intval', explode('-', $datePart));
        if (!checkdate($m, $d, $y)) {
            return null;
        }
        try {
            return self::iso(new DateTimeImmutable($value));
        } catch (\Exception) {
            return null;
        }
    }

    public static function isDate(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
