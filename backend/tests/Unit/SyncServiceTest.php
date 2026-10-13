<?php

declare(strict_types=1);

namespace Quitt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quitt\Db\Database;
use Quitt\Http\ApiException;
use Quitt\Service\ReceiptService;
use Quitt\Service\SyncService;
use Quitt\Service\TrashService;
use Quitt\Tests\TestHelpers;

/** Sync-Logik direkt (ohne HTTP): Nummernvergabe, LWW, Versionen, Tombstones, Beleg-Papierkorb. */
final class SyncServiceTest extends TestCase
{
    use TestHelpers;

    private SyncService $sync;

    protected function setUp(): void
    {
        $this->bootContainer();
        $this->migrate();
        $this->sync = $this->c->get(SyncService::class);
    }

    protected function tearDown(): void
    {
        $this->c->get(Database::class)->close();
        $this->cleanupDataDir();
    }

    public function testNumbersFollowCreationOrderAndAreNeverReused(): void
    {
        $r = $this->sync->apply(['entries' => [
            self::entry(2, ['created_at' => '2026-10-01T10:00:02Z']),
            self::entry(1, ['created_at' => '2026-10-01T10:00:01Z']),
        ]]);
        $numbers = array_column($r['applied'], 'number', 'id');
        self::assertSame(1, $numbers[self::uuid(1)]);
        self::assertSame(2, $numbers[self::uuid(2)]);

        $this->sync->apply(['entries' => [self::entry(2, ['deleted_at' => '2026-10-02T00:00:00Z', 'updated_at' => '2026-10-02T00:00:00Z'])]]);
        $this->c->get(TrashService::class)->emptyTrash();
        $r = $this->sync->apply(['entries' => [self::entry(3)]]);
        self::assertSame(3, $r['applied'][0]['number'], 'Luecke bleibt, Nummer 2 wird nicht neu vergeben');
    }

    public function testLastWriteWinsAndIdempotentRepeat(): void
    {
        $first = $this->sync->apply(['entries' => [self::entry(1)]]);
        $v1 = $first['applied'][0]['version'];

        $repeat = $this->sync->apply(['entries' => [self::entry(1)]]);
        self::assertSame($v1, $repeat['applied'][0]['version'], 'gleiche Lieferung -> keine neue Version');
        self::assertSame([], $repeat['conflicts']);

        $newer = $this->sync->apply(['entries' => [self::entry(1, ['amount_cents' => 999, 'updated_at' => '2026-10-05T00:00:00Z'])]]);
        self::assertSame(999, $newer['applied'][0]['amount_cents']);
        self::assertGreaterThan($v1, $newer['applied'][0]['version']);
        self::assertSame(1, $newer['applied'][0]['number'], 'Nummer bleibt bei Aenderung');

        $older = $this->sync->apply(['entries' => [self::entry(1, ['amount_cents' => 5, 'updated_at' => '2026-10-03T00:00:00Z'])]]);
        self::assertSame([], $older['applied']);
        self::assertSame(self::uuid(1), $older['conflicts'][0]['id']);
        self::assertSame(999, $older['conflicts'][0]['server']['amount_cents']);
    }

    public function testValidationIsAtomicAndKeyedById(): void
    {
        try {
            $this->sync->apply(['entries' => [
                self::entry(1),
                self::entry(2, ['amount_cents' => 0, 'reason' => '']),
                self::entry(3, ['type' => 'in', 'purpose' => null]),
            ]]);
            self::fail('422 erwartet');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            $f = $e->extra['fields'];
            self::assertSame(['amount_cents', 'reason'], array_keys($f[self::uuid(2)]));
            self::assertSame(['purpose'], array_keys($f[self::uuid(3)]));
            self::assertArrayNotHasKey(self::uuid(1), $f);
        }
        self::assertSame([], $this->sync->changesSince(0)['entries'], 'nichts gespeichert');
    }

    public function testFieldsNotMatchingTypeAreNulled(): void
    {
        $r = $this->sync->apply(['entries' => [self::entry(1, ['type' => 'in', 'purpose' => 'Beitrag Oktober', 'reason' => 'x', 'receipt_id' => self::uuid(99)])]]);
        $e = $r['applied'][0];
        self::assertSame('Beitrag Oktober', $e['purpose']);
        self::assertNull($e['reason']);
        self::assertNull($e['category']);
        self::assertNull($e['item']);
        self::assertNull($e['receipt_id']);
    }

    public function testChangesSinceIncludesTrashAndPurged(): void
    {
        $this->sync->apply(['entries' => [self::entry(1), self::entry(2)]]);
        $cursor = $this->sync->changesSince(0)['cursor'];
        $this->sync->apply(['entries' => [self::entry(1, ['deleted_at' => '2026-10-02T00:00:00Z', 'updated_at' => '2026-10-02T00:00:00Z'])]]);
        $d = $this->sync->changesSince($cursor);
        self::assertCount(1, $d['entries']);
        self::assertNotNull($d['entries'][0]['deleted_at']);

        $this->c->get(TrashService::class)->deleteEntry(self::uuid(1));
        $d2 = $this->sync->changesSince($d['cursor']);
        self::assertSame([self::uuid(1)], $d2['purged']['entries']);
        self::assertGreaterThan($d['cursor'], $d2['cursor']);

        // endgueltig geloescht -> wird nicht wiederbelebt
        $r = $this->sync->apply(['entries' => [self::entry(1, ['updated_at' => '2026-12-01T00:00:00Z'])]]);
        self::assertSame([], $r['applied']);
        self::assertNull($this->sync->findEntry(self::uuid(1)));
    }

    public function testReceiptFollowsTrashStateOfItsEntries(): void
    {
        $rid = self::uuid(50);
        $this->c->get(ReceiptService::class)->store($rid, self::jpeg());
        $this->sync->apply(['entries' => [self::entry(1, ['receipt_id' => $rid]), self::entry(2, ['receipt_id' => $rid])]]);
        $receipts = $this->c->get(ReceiptService::class);

        $this->sync->apply(['entries' => [self::entry(1, ['receipt_id' => $rid, 'deleted_at' => '2026-10-02T00:00:00Z', 'updated_at' => '2026-10-02T00:00:00Z'])]]);
        self::assertNull($receipts->find($rid)['deleted_at'], 'eine Buchung noch aktiv');

        $this->sync->apply(['entries' => [self::entry(2, ['receipt_id' => $rid, 'deleted_at' => '2026-10-02T00:00:00Z', 'updated_at' => '2026-10-02T00:00:00Z'])]]);
        self::assertNotNull($receipts->find($rid)['deleted_at'], 'alle im Papierkorb');

        $this->sync->apply(['entries' => [self::entry(2, ['receipt_id' => $rid, 'deleted_at' => null, 'updated_at' => '2026-10-03T00:00:00Z'])]]);
        self::assertNull($receipts->find($rid)['deleted_at'], 'wiederhergestellt');
    }

    public function testUnknownReceiptIsRejected(): void
    {
        $this->expectException(ApiException::class);
        $this->sync->apply(['entries' => [self::entry(1, ['receipt_id' => self::uuid(77)])]]);
    }
}
