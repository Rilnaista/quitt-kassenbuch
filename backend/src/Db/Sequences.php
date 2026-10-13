<?php

declare(strict_types=1);

namespace Quitt\Db;

/**
 * Monotone Zaehler: 'version' (globaler Aenderungszaehler fuer Sync) und
 * 'entry_number' (Belegnummer, nie wiederverwendet). Nur innerhalb einer
 * Database::transaction() aufrufen.
 */
final class Sequences
{
    public const VERSION = 'version';
    public const ENTRY_NUMBER = 'entry_number';

    public function __construct(private readonly Database $db)
    {
    }

    public function next(string $name): int
    {
        $this->db->run('UPDATE sequences SET value = value + 1 WHERE name = ?', [$name]);
        return (int) $this->db->scalar('SELECT value FROM sequences WHERE name = ?', [$name]);
    }

    public function current(string $name): int
    {
        return (int) ($this->db->scalar('SELECT value FROM sequences WHERE name = ?', [$name]) ?? 0);
    }
}
