<?php

declare(strict_types=1);

namespace Quitt\I18n;

/**
 * Nutzersichtbare Texte aus lang/<locale>.php (flaches Array schluessel => Text).
 * Platzhalter {name} werden ersetzt. Fehlender Schluessel -> Schluessel selbst (sichtbar im Test).
 */
final class Lang
{
    /** @param array<string, string> $messages */
    public function __construct(private readonly array $messages)
    {
    }

    public static function load(string $dir, string $locale = 'de'): self
    {
        $file = $dir . '/' . $locale . '.php';
        $messages = is_file($file) ? require $file : [];
        return new self(is_array($messages) ? $messages : []);
    }

    public function has(string $key): bool
    {
        return isset($this->messages[$key]);
    }

    /** @param array<string, string|int> $params */
    public function t(string $key, array $params = []): string
    {
        $text = $this->messages[$key] ?? $key;
        if ($params === []) {
            return $text;
        }
        $map = [];
        foreach ($params as $k => $v) {
            $map['{' . $k . '}'] = (string) $v;
        }
        return strtr($text, $map);
    }
}
