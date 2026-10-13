<?php

declare(strict_types=1);

namespace Quitt\Log;

/**
 * Entfernt Geheimnisse aus Log-/Diagnose-Daten. Auch fuer das spaetere Diagnosepaket nutzbar.
 */
final class Masker
{
    public const MASK = '***';

    /** exakt passende Schluessel (klein geschrieben) */
    private const KEYS = [
        'pin', 'old_pin', 'new_pin', 'pin_hash', 'recovery_code', 'recovery_hash',
        'ai_key', 'api_key', 'apikey', 'x-api-key', 'authorization', 'ticket', 'cookie',
    ];

    /** Teilstrings, die einen Schluessel als geheim markieren */
    private const PARTS = ['token', 'secret', 'password', 'passwd'];

    public static function isSecretKey(string $key): bool
    {
        $k = strtolower($key);
        if (in_array($k, self::KEYS, true)) {
            return true;
        }
        foreach (self::PARTS as $part) {
            if (str_contains($k, $part)) {
                return true;
            }
        }
        return false;
    }

    public static function mask(mixed $data, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '…';
        }
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $out[$k] = is_string($k) && self::isSecretKey($k) && $v !== null && $v !== ''
                    ? self::MASK
                    : self::mask($v, $depth + 1);
            }
            return $out;
        }
        if (is_string($data)) {
            return self::maskText($data);
        }
        return $data;
    }

    /** Maskiert Bearer-Token und ticket=-Parameter in Freitext (z. B. URLs). */
    public static function maskText(string $text): string
    {
        $text = preg_replace('/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i', '$1' . self::MASK, $text) ?? $text;
        return preg_replace('/((?:ticket|token)=)[^&\s"]+/i', '$1' . self::MASK, $text) ?? $text;
    }
}
