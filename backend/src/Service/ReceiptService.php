<?php

declare(strict_types=1);

namespace Quitt\Service;

use Psr\Log\LoggerInterface;
use Quitt\App\Config;
use Quitt\Db\Database;
use Quitt\Db\Sequences;
use Quitt\Http\ApiException;
use Quitt\Support\Clock;
use Quitt\Support\Time;

/**
 * Belege: Datei unter <data>/receipts/<id> (ohne Endung), Metadaten in receipts.
 * MIME wird anhand der Magic Bytes erkannt (kein fileinfo/gd noetig).
 *
 * Papierkorb-Zustand: deleted_at wird gesetzt, sobald ALLE verweisenden Buchungen im Papierkorb
 * liegen, und geloescht, sobald wieder eine aktiv ist. Endgueltig entfernt wird ein Beleg erst,
 * wenn KEINE Buchung (auch nicht im Papierkorb) mehr auf ihn verweist.
 */
final class ReceiptService
{
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly Database $db,
        private readonly Sequences $seq,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly LoggerInterface $log,
    ) {
    }

    public static function detectMime(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return null;
    }

    public function maxBytes(): int
    {
        return (int) $this->config->get('receipt_max_bytes', 5 * 1024 * 1024);
    }

    public function dir(): string
    {
        return $this->config->path('receipts');
    }

    public function filePath(string $id): string
    {
        return $this->dir() . '/' . strtolower($id);
    }

    public function find(string $id): ?array
    {
        $row = $this->db->one('SELECT * FROM receipts WHERE id = ?', [strtolower($id)]);
        return $row === null ? null : self::map($row);
    }

    public function exists(string $id): bool
    {
        return $this->db->scalar('SELECT 1 FROM receipts WHERE id = ?', [strtolower($id)]) !== null;
    }

    /** @return array<string, mixed> API-Darstellung */
    public static function map(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'mime' => (string) $row['mime'],
            'size' => (int) $row['size'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'deleted_at' => $row['deleted_at'] === null ? null : (string) $row['deleted_at'],
            'version' => (int) $row['version'],
        ];
    }

    /**
     * Idempotentes Speichern (PUT). Gleicher Inhalt -> unveraendert.
     * @return array{receipt: array<string, mixed>, created: bool}
     */
    public function store(string $id, string $bytes): array
    {
        $id = strtolower($id);
        if ($bytes === '') {
            throw ApiException::validation(['file' => 'validation.required']);
        }
        if (strlen($bytes) > $this->maxBytes()) {
            throw ApiException::validation(['file' => 'validation.file_too_large']);
        }
        $mime = self::detectMime($bytes);
        if ($mime === null) {
            throw ApiException::validation(['file' => 'validation.file_type']);
        }
        $sha = hash('sha256', $bytes);
        $existing = $this->db->one('SELECT * FROM receipts WHERE id = ?', [$id]);
        if ($existing !== null && $existing['sha256'] === $sha && is_file($this->filePath($id))) {
            return ['receipt' => self::map($existing), 'created' => false];
        }

        $tmp = $this->filePath($id) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($tmp);
            throw new \RuntimeException('Belegdatei nicht schreibbar');
        }
        try {
            $result = $this->db->transaction(function () use ($id, $mime, $bytes, $sha): array {
                $now = Time::iso($this->clock->now());
                $version = $this->seq->next(Sequences::VERSION);
                $existing = $this->db->one('SELECT * FROM receipts WHERE id = ?', [$id]);
                if ($existing === null) {
                    $this->db->run(
                        'INSERT INTO receipts (id, mime, size, sha256, created_at, updated_at, deleted_at, version) VALUES (?, ?, ?, ?, ?, ?, NULL, ?)',
                        [$id, $mime, strlen($bytes), $sha, $now, $now, $version]
                    );
                    $this->db->run("DELETE FROM purged WHERE kind = 'receipt' AND id = ?", [$id]);
                    $created = true;
                } else {
                    $this->db->run(
                        'UPDATE receipts SET mime = ?, size = ?, sha256 = ?, updated_at = ?, version = ? WHERE id = ?',
                        [$mime, strlen($bytes), $sha, $now, $version, $id]
                    );
                    $created = false;
                }
                return ['created' => $created];
            });
            // Datei erst nach Commit an ihren Platz; scheitert der Commit, bleibt die alte Datei.
            if (!@rename($tmp, $this->filePath($id))) {
                throw new \RuntimeException('Belegdatei nicht verschiebbar');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
        $this->log->info('receipt stored', ['id' => $id, 'size' => strlen($bytes), 'mime' => $mime, 'created' => $result['created']]);
        return ['receipt' => $this->find($id), 'created' => $result['created']];
    }

    /**
     * Papierkorb-Zustand der Belege an die Buchungen angleichen (innerhalb einer Transaktion).
     * @param string[] $ids
     */
    public function refreshTrashState(array $ids): void
    {
        foreach (array_unique(array_filter($ids)) as $id) {
            $row = $this->db->one(
                'SELECT r.deleted_at,
                        (SELECT COUNT(*) FROM entries e WHERE e.receipt_id = r.id) AS refs,
                        (SELECT COUNT(*) FROM entries e WHERE e.receipt_id = r.id AND e.deleted_at IS NULL) AS active
                 FROM receipts r WHERE r.id = ?',
                [$id]
            );
            if ($row === null || (int) $row['refs'] === 0) {
                continue;
            }
            $inTrash = (int) $row['active'] === 0;
            if ($inTrash && $row['deleted_at'] === null) {
                $now = Time::iso($this->clock->now());
                $this->db->run('UPDATE receipts SET deleted_at = ?, updated_at = ?, version = ? WHERE id = ?', [$now, $now, $this->seq->next(Sequences::VERSION), $id]);
            } elseif (!$inTrash && $row['deleted_at'] !== null) {
                $now = Time::iso($this->clock->now());
                $this->db->run('UPDATE receipts SET deleted_at = NULL, updated_at = ?, version = ? WHERE id = ?', [$now, $this->seq->next(Sequences::VERSION), $id]);
            }
        }
    }

    /**
     * Entfernt Belege ohne jede verweisende Buchung (DB-Zeile + Tombstone), innerhalb einer Transaktion.
     * Dateien danach mit deleteFiles() loeschen (erst nach Commit).
     * @param string[] $ids
     * @return string[] entfernte IDs
     */
    public function purgeUnreferenced(array $ids): array
    {
        $purged = [];
        foreach (array_unique(array_filter($ids)) as $id) {
            $refs = (int) $this->db->scalar('SELECT COUNT(*) FROM entries WHERE receipt_id = ?', [$id]);
            if ($refs > 0 || !$this->exists($id)) {
                continue;
            }
            $this->db->run('DELETE FROM receipts WHERE id = ?', [$id]);
            $this->db->run(
                "INSERT INTO purged (kind, id, version, purged_at) VALUES ('receipt', ?, ?, ?)
                 ON CONFLICT(kind, id) DO UPDATE SET version = excluded.version, purged_at = excluded.purged_at",
                [$id, $this->seq->next(Sequences::VERSION), Time::iso($this->clock->now())]
            );
            $purged[] = $id;
        }
        return $purged;
    }

    /**
     * Belege, auf die keine Buchung verweist und die aelter als die Karenzzeit sind
     * (Upload ohne nachfolgende Buchung). Karenz schuetzt laufende Offline-Abgleiche.
     * @return string[]
     */
    public function staleOrphanIds(): array
    {
        $hours = max(0, (int) $this->config->get('orphan_receipt_grace_hours', 24));
        $cutoff = Time::iso($this->clock->now()->modify('-' . $hours . ' hours'));
        return array_map('strval', array_column($this->db->all(
            'SELECT id FROM receipts r WHERE r.created_at < ? AND NOT EXISTS (SELECT 1 FROM entries e WHERE e.receipt_id = r.id)',
            [$cutoff]
        ), 'id'));
    }

    /** @param string[] $ids */
    public function deleteFiles(array $ids): void
    {
        foreach ($ids as $id) {
            $path = $this->filePath($id);
            if (is_file($path) && !@unlink($path)) {
                $this->log->warning('receipt file not deletable', ['id' => $id]);
            }
        }
    }

    /** Loescht Dateien im Belegordner ohne Datenbankzeile (z. B. nach Abbruch). @return int Anzahl */
    public function deleteOrphanFiles(): int
    {
        $count = 0;
        $cutoff = $this->clock->now()->getTimestamp() - 3600;
        foreach (scandir($this->dir()) ?: [] as $name) {
            $path = $this->dir() . '/' . $name;
            if (!is_file($path) || $name === 'index.php' || str_starts_with($name, '.')) {
                continue;
            }
            if (str_ends_with($name, '.tmp')) {
                $orphan = (int) @filemtime($path) < $cutoff;
            } else {
                $orphan = preg_match('/^[0-9a-f-]{36}$/', $name) === 1 && !$this->exists($name);
            }
            if ($orphan && @unlink($path)) {
                $count++;
            }
        }
        return $count;
    }
}
