<?php

declare(strict_types=1);

namespace Quitt\Db;

/** Schluessel/Wert-Speicher fuer interne Zustaende (PIN-Hash, Recovery-Hash, Drosselung ...). Nie nach aussen geben. */
final class Meta
{
    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $key): ?string
    {
        $v = $this->db->scalar('SELECT value FROM meta WHERE key = ?', [$key]);
        return $v === null ? null : (string) $v;
    }

    public function set(string $key, string $value): void
    {
        $this->db->run(
            'INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            [$key, $value]
        );
    }

    public function delete(string $key): void
    {
        $this->db->run('DELETE FROM meta WHERE key = ?', [$key]);
    }
}
