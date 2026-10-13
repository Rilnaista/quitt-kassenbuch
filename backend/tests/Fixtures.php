<?php

declare(strict_types=1);

namespace Quitt\Tests;

/** Statische Test-Hilfen (Pfade, Testdaten). */
final class Fixtures
{
    public static function appDir(): string
    {
        return dirname(__DIR__);
    }

    public static function rmrf(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    public static function uuid(int $n): string
    {
        return sprintf('%08x-0000-4000-8000-%012x', $n, $n);
    }

    /** Minimal gueltiges Ausgaben-/Einnahmen-Objekt fuer POST /sync */
    public static function entry(int $n, array $over = []): array
    {
        return array_merge([
            'id' => self::uuid($n),
            'type' => 'out',
            'date' => '2026-10-01',
            'amount_cents' => 1000 + $n,
            'purpose' => null,
            'reason' => 'Geburtstag',
            'category' => 'Anna',
            'item' => 'Kuchen ' . $n,
            'receipt_id' => null,
            'created_at' => sprintf('2026-10-01T10:00:%02d.000Z', $n % 60),
            'updated_at' => sprintf('2026-10-01T10:00:%02d.000Z', $n % 60),
            'deleted_at' => null,
        ], $over);
    }

    public static function jpeg(string $salt = ''): string
    {
        return "\xFF\xD8\xFF\xE0" . str_repeat('x', 100) . $salt;
    }
}
