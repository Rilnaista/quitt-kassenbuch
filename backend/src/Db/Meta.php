<?php

declare(strict_types=1);

namespace Quitt\Db;

use Quitt\Support\Uuid;

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

    /** Kennung des Datenbestands (UUID v4); wird bei Bedarf erzeugt. */
    public function dataId(): string
    {
        $id = $this->get('data_id');
        if ($id === null || !Uuid::isV4($id)) {
            $id = $this->regenerateDataId();
        }
        return $id;
    }

    /** Neue Kennung vergeben (nach jedem Ersetzen der Datenbank). */
    public function regenerateDataId(): string
    {
        $id = Uuid::v4();
        $this->set('data_id', $id);
        return $id;
    }

    public function delete(string $key): void
    {
        $this->db->run('DELETE FROM meta WHERE key = ?', [$key]);
    }
}
