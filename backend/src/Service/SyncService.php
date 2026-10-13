<?php

declare(strict_types=1);

namespace Quitt\Service;

use Psr\Log\LoggerInterface;
use Quitt\App\Config;
use Quitt\Db\Database;
use Quitt\Db\Meta;
use Quitt\Db\Sequences;
use Quitt\Http\ApiException;
use Quitt\Support\Clock;
use Quitt\Support\Time;
use Quitt\Support\Uuid;

/**
 * Abgleich (Hauptweg des Frontends).
 * - GET: alle Aenderungen mit version > since (inkl. Papierkorb) + Tombstones.
 * - POST: Upsert per id, Last-Write-Wins ueber updated_at; number beim ersten Speichern,
 *   version global monoton (Sequences). Ganze Lieferung ist atomar (422 = nichts gespeichert).
 */
final class SyncService
{
    public const TEXT_MAX = 200;
    public const NOTE_MAX = 100;
    public const AMOUNT_MAX = 10_000_000_000; // 100 Mio. EUR in Cent

    private const CONTENT_FIELDS = ['type', 'date', 'amount_cents', 'purpose', 'reason', 'category', 'item', 'note', 'receipt_id', 'updated_at', 'deleted_at'];

    public function __construct(
        private readonly Database $db,
        private readonly Sequences $seq,
        private readonly ReceiptService $receipts,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly LoggerInterface $log,
    ) {
    }

    /** @return array<string, mixed> API-Darstellung einer Buchung */
    public static function mapEntry(array $row): array
    {
        $nullable = static fn ($v) => $v === null ? null : (string) $v;
        return [
            'id' => (string) $row['id'],
            'number' => $row['number'] === null ? null : (int) $row['number'],
            'type' => (string) $row['type'],
            'date' => (string) $row['date'],
            'amount_cents' => (int) $row['amount_cents'],
            'purpose' => $nullable($row['purpose']),
            'reason' => $nullable($row['reason']),
            'category' => $nullable($row['category']),
            'item' => $nullable($row['item']),
            'note' => $nullable($row['note'] ?? null),
            'receipt_id' => $nullable($row['receipt_id']),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'deleted_at' => $nullable($row['deleted_at']),
            'version' => (int) $row['version'],
        ];
    }

    public function findEntry(string $id): ?array
    {
        $row = $this->db->one('SELECT * FROM entries WHERE id = ?', [strtolower($id)]);
        return $row === null ? null : self::mapEntry($row);
    }

    /** @return array<string, mixed> */
    public function changesSince(int $since): array
    {
        // Lesen in einer Transaktion = konsistenter Schnappschuss (WAL)
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $entries = array_map([self::class, 'mapEntry'], $this->db->all('SELECT * FROM entries WHERE version > ? ORDER BY version', [$since]));
            $receipts = array_map([ReceiptService::class, 'map'], $this->db->all('SELECT * FROM receipts WHERE version > ? ORDER BY version', [$since]));
            $purged = ['entries' => [], 'receipts' => []];
            foreach ($this->db->all('SELECT kind, id FROM purged WHERE version > ? ORDER BY version', [$since]) as $p) {
                $purged[$p['kind'] === 'entry' ? 'entries' : 'receipts'][] = (string) $p['id'];
            }
            $cursor = $this->seq->current(Sequences::VERSION);
        } finally {
            $pdo->commit();
        }
        return [
            'data_id' => (new Meta($this->db))->dataId(),
            'entries' => $entries,
            'receipts' => $receipts,
            'purged' => $purged,
            'cursor' => $cursor,
            'server_time' => Time::iso($this->clock->now()),
        ];
    }

    /**
     * @param array<string, mixed> $payload { entries: [...] }
     * @return array{data_id: string, applied: list<array>, conflicts: list<array{id: string, server: array}>, cursor: int}
     */
    public function apply(array $payload): array
    {
        $dataId = (new Meta($this->db))->dataId();
        $client = $payload['data_id'] ?? null;
        if ($client !== null && (!is_string($client) || $client !== $dataId)) {
            throw new ApiException(409, 'data_reset', null, ['data_id' => $dataId]);
        }
        $list = $payload['entries'] ?? null;
        if (!is_array($list) || ($list !== [] && !array_is_list($list))) {
            throw ApiException::validation(['entries' => 'validation.list']);
        }
        $max = (int) $this->config->get('sync_max_entries', 500);
        if (count($list) > $max) {
            throw ApiException::validation(['entries' => 'validation.too_many']);
        }

        $clean = [];
        $errors = [];
        foreach ($list as $i => $raw) {
            $key = is_array($raw) && is_string($raw['id'] ?? null) && $raw['id'] !== '' ? strtolower($raw['id']) : '#' . $i;
            [$entry, $fieldErrors] = $this->validate($raw);
            if ($fieldErrors !== []) {
                $errors[$key] = $fieldErrors;
            } else {
                $clean[] = ['i' => $i, 'e' => $entry];
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        // Neue Buchungen erhalten Nummern in der Reihenfolge ihres Anlegens.
        usort($clean, static fn ($a, $b) => [$a['e']['created_at'], $a['i']] <=> [$b['e']['created_at'], $b['i']]);

        $result = $this->db->transaction(function () use ($clean, $dataId): array {
            $applied = [];
            $conflicts = [];
            $touchedReceipts = [];
            foreach ($clean as ['e' => $in]) {
                $id = $in['id'];
                if ($this->db->scalar("SELECT 1 FROM purged WHERE kind = 'entry' AND id = ?", [$id]) !== null) {
                    continue; // endgueltig geloescht: nicht wiederbeleben, Client erfaehrt es ueber purged
                }
                if ($in['receipt_id'] !== null && !$this->receipts->exists($in['receipt_id'])) {
                    throw ApiException::validation([$id => ['receipt_id' => 'validation.receipt_missing']]);
                }
                $row = $this->db->one('SELECT * FROM entries WHERE id = ?', [$id]);
                if ($row === null) {
                    $number = $this->seq->next(Sequences::ENTRY_NUMBER);
                    $version = $this->seq->next(Sequences::VERSION);
                    $this->db->run(
                        'INSERT INTO entries (id, number, type, date, amount_cents, purpose, reason, category, item, note, receipt_id, created_at, updated_at, deleted_at, version)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$id, $number, $in['type'], $in['date'], $in['amount_cents'], $in['purpose'], $in['reason'], $in['category'], $in['item'], $in['note'],
                            $in['receipt_id'], $in['created_at'], $in['updated_at'], $in['deleted_at'], $version]
                    );
                    $touchedReceipts[] = $in['receipt_id'];
                } else {
                    $server = self::mapEntry($row);
                    if ($in['updated_at'] <= $server['updated_at']) {
                        if ($this->sameContent($in, $server)) {
                            $applied[$id] = $server; // Wiederholung derselben Lieferung
                        } else {
                            $conflicts[$id] = ['id' => $id, 'server' => $server];
                            unset($applied[$id]);
                        }
                        continue;
                    }
                    $version = $this->seq->next(Sequences::VERSION);
                    $this->db->run(
                        'UPDATE entries SET type = ?, date = ?, amount_cents = ?, purpose = ?, reason = ?, category = ?, item = ?, note = ?, receipt_id = ?,
                                updated_at = ?, deleted_at = ?, version = ? WHERE id = ?',
                        [$in['type'], $in['date'], $in['amount_cents'], $in['purpose'], $in['reason'], $in['category'], $in['item'], $in['note'], $in['receipt_id'],
                            $in['updated_at'], $in['deleted_at'], $version, $id]
                    );
                    $touchedReceipts[] = $server['receipt_id'];
                    $touchedReceipts[] = $in['receipt_id'];
                }
                unset($conflicts[$id]);
                $applied[$id] = self::mapEntry($this->db->one('SELECT * FROM entries WHERE id = ?', [$id]));
            }
            $this->receipts->refreshTrashState(array_values(array_filter($touchedReceipts)));
            return [
                'data_id' => $dataId,
                'applied' => array_values($applied),
                'conflicts' => array_values($conflicts),
                'cursor' => $this->seq->current(Sequences::VERSION),
            ];
        });
        if ($result['applied'] !== [] || $result['conflicts'] !== []) {
            $this->log->info('sync applied', ['applied' => count($result['applied']), 'conflicts' => count($result['conflicts'])]);
        }
        return $result;
    }

    /**
     * Validiert und normalisiert eine Buchung. Felder, die zum Typ nicht passen, werden auf null gesetzt.
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function validate(mixed $raw): array
    {
        if (!is_array($raw) || array_is_list($raw)) {
            return [[], ['entry' => 'validation.object']];
        }
        $err = [];
        $e = [];

        $e['id'] = is_string($raw['id'] ?? null) ? strtolower($raw['id']) : null;
        if (!Uuid::isV4($e['id'])) {
            $err['id'] = 'validation.uuid';
        }
        $e['type'] = $raw['type'] ?? null;
        if (!in_array($e['type'], ['in', 'out'], true)) {
            $err['type'] = 'validation.entry_type';
        }
        $e['date'] = $raw['date'] ?? null;
        if (!Time::isDate($e['date'])) {
            $err['date'] = 'validation.date';
        }
        $amount = $raw['amount_cents'] ?? null;
        if (!is_int($amount) || $amount <= 0 || $amount > self::AMOUNT_MAX) {
            $err['amount_cents'] = 'validation.amount';
        }
        $e['amount_cents'] = $amount;

        foreach (['purpose', 'reason', 'category', 'item'] as $f) {
            $v = $raw[$f] ?? null;
            if ($v !== null && !is_string($v)) {
                $err[$f] = 'validation.string';
                $v = null;
            }
            $v = $v === null ? null : trim(preg_replace('/\s+/u', ' ', $v) ?? $v);
            if ($v !== null && mb_strlen($v) > self::TEXT_MAX) {
                $err[$f] = 'validation.too_long';
            }
            $e[$f] = $v === '' ? null : $v;
        }
        $note = $raw['note'] ?? null;
        if ($note !== null && !is_string($note)) {
            $err['note'] = 'validation.string';
            $note = null;
        }
        $note = $note === null ? null : trim($note);
        if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
            $err['note'] = 'validation.note_too_long';
        }
        $e['note'] = $note === '' ? null : $note;

        $rid = $raw['receipt_id'] ?? null;
        if ($rid !== null && !Uuid::isV4($rid)) {
            $err['receipt_id'] = 'validation.uuid';
        }
        $e['receipt_id'] = is_string($rid) ? strtolower($rid) : null;

        if ($e['type'] === 'in') {
            $e['reason'] = $e['category'] = $e['item'] = $e['receipt_id'] = null;
            unset($err['reason'], $err['category'], $err['item'], $err['receipt_id']);
            if ($e['purpose'] === null && !isset($err['purpose'])) {
                $err['purpose'] = 'validation.required';
            }
        } elseif ($e['type'] === 'out') {
            $e['purpose'] = null;
            unset($err['purpose']);
            foreach (['reason', 'category', 'item'] as $f) {
                if ($e[$f] === null && !isset($err[$f])) {
                    $err[$f] = 'validation.required';
                }
            }
        }

        foreach (['created_at', 'updated_at'] as $f) {
            $e[$f] = Time::normalize($raw[$f] ?? null);
            if ($e[$f] === null) {
                $err[$f] = 'validation.timestamp';
            }
        }
        $deleted = $raw['deleted_at'] ?? null;
        $e['deleted_at'] = $deleted === null ? null : Time::normalize($deleted);
        if ($deleted !== null && $e['deleted_at'] === null) {
            $err['deleted_at'] = 'validation.timestamp';
        }
        return [$e, $err];
    }

    private function sameContent(array $in, array $server): bool
    {
        foreach (self::CONTENT_FIELDS as $f) {
            if ($in[$f] !== $server[$f]) {
                return false;
            }
        }
        return true;
    }
}
