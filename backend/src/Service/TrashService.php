<?php

declare(strict_types=1);

namespace Quitt\Service;

use Psr\Log\LoggerInterface;
use Quitt\Db\Database;
use Quitt\Db\Sequences;
use Quitt\Http\ApiException;
use Quitt\Support\Clock;
use Quitt\Support\Time;

/**
 * Endgueltiges Loeschen aus dem Papierkorb. Belegnummern werden nie neu vergeben
 * (Sequenz laeuft weiter). Tombstones in purged, damit andere Geraete nachziehen.
 */
final class TrashService
{
    public function __construct(
        private readonly Database $db,
        private readonly Sequences $seq,
        private readonly ReceiptService $receipts,
        private readonly Clock $clock,
        private readonly LoggerInterface $log,
    ) {
    }

    public function deleteEntry(string $id): void
    {
        $id = strtolower($id);
        $purgedReceipts = $this->db->transaction(function () use ($id): array {
            $row = $this->db->one('SELECT id, deleted_at, receipt_id FROM entries WHERE id = ?', [$id]);
            if ($row === null) {
                throw ApiException::notFound();
            }
            if ($row['deleted_at'] === null) {
                throw new ApiException(409, 'not_in_trash');
            }
            $this->purgeEntry($id);
            return $this->receipts->purgeUnreferenced([(string) $row['receipt_id']]);
        });
        $this->receipts->deleteFiles($purgedReceipts);
        $this->log->info('entry purged', ['id' => $id, 'receipts_purged' => count($purgedReceipts)]);
    }

    /** Papierkorb leeren inkl. verwaister Belege/Belegdateien. @return int Anzahl entfernter Buchungen */
    public function emptyTrash(): int
    {
        [$count, $purgedReceipts] = $this->db->transaction(function (): array {
            $rows = $this->db->all('SELECT id, receipt_id FROM entries WHERE deleted_at IS NOT NULL ORDER BY number');
            foreach ($rows as $row) {
                $this->purgeEntry((string) $row['id']);
            }
            $candidates = array_merge(
                array_map('strval', array_filter(array_column($rows, 'receipt_id'))),
                $this->receipts->staleOrphanIds()
            );
            return [count($rows), $this->receipts->purgeUnreferenced($candidates)];
        });
        $this->receipts->deleteFiles($purgedReceipts);
        $orphanFiles = $this->receipts->deleteOrphanFiles();
        $this->log->info('trash emptied', ['entries' => $count, 'receipts' => count($purgedReceipts), 'orphan_files' => $orphanFiles]);
        return $count;
    }

    private function purgeEntry(string $id): void
    {
        $this->db->run('DELETE FROM entries WHERE id = ?', [$id]);
        $this->db->run(
            "INSERT INTO purged (kind, id, version, purged_at) VALUES ('entry', ?, ?, ?)
             ON CONFLICT(kind, id) DO UPDATE SET version = excluded.version, purged_at = excluded.purged_at",
            [$id, $this->seq->next(Sequences::VERSION), Time::iso($this->clock->now())]
        );
    }
}
