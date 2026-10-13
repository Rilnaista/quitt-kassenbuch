<?php

declare(strict_types=1);

namespace Quitt\Tests\Api;

use Quitt\Service\ReceiptService;
use Quitt\Tests\ApiTestCase;

final class TrashApiTest extends ApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->install();
        $this->token = $this->login();
    }

    private function trash(int $n, array $over = []): void
    {
        $r = $this->request('POST', '/sync', ['entries' => [self::entry($n, array_merge(['deleted_at' => '2026-10-05T00:00:00Z', 'updated_at' => '2026-10-05T00:00:00Z'], $over))]], $this->token);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
    }

    public function testDeleteEntry(): void
    {
        $this->request('POST', '/sync', ['entries' => [self::entry(1)]], $this->token);
        $this->assertError($this->request('DELETE', '/entries/' . self::uuid(1), null, $this->token), 409, 'not_in_trash');
        $this->trash(1);
        self::assertSame(204, $this->request('DELETE', '/entries/' . self::uuid(1), null, $this->token)->getStatusCode());
        $this->assertError($this->request('DELETE', '/entries/' . self::uuid(1), null, $this->token), 404, 'not_found');
        $this->assertError($this->request('DELETE', '/entries/kein-uuid', null, $this->token), 404, 'not_found');

        $pull = $this->decode($this->request('GET', '/sync?since=0', null, $this->token));
        self::assertSame([self::uuid(1)], $pull['purged']['entries']);
        self::assertSame([], $pull['entries']);
    }

    public function testDeleteRequiresPin(): void
    {
        $this->setReadWithoutPin(true);
        $this->assertError($this->request('DELETE', '/entries/' . self::uuid(1)), 403, 'pin_required');
    }

    public function testEmptyTrashRemovesOrphanReceipts(): void
    {
        $shared = self::uuid(100);
        $single = self::uuid(101);
        $stale = self::uuid(102);
        self::assertSame(201, $this->multipart('PUT', '/receipts/' . $shared, self::jpeg('a'), $this->token)->getStatusCode());
        self::assertSame(201, $this->multipart('PUT', '/receipts/' . $single, self::jpeg('b'), $this->token)->getStatusCode());
        self::assertSame(201, $this->multipart('PUT', '/receipts/' . $stale, self::jpeg('c'), $this->token)->getStatusCode());
        file_put_contents($this->dataDir . '/receipts/' . self::uuid(103), 'verwaist');

        $this->request('POST', '/sync', ['entries' => [
            self::entry(1, ['receipt_id' => $shared]), self::entry(2, ['receipt_id' => $shared]),
            self::entry(3, ['receipt_id' => $single]), self::entry(4),
        ]], $this->token);
        $this->trash(1, ['receipt_id' => $shared]);
        $this->trash(3, ['receipt_id' => $single]);

        $this->clock->advance(25 * 3600); // unverknuepfter Upload ist aelter als die Karenzzeit
        $r = $this->request('POST', '/trash/empty', null, $this->token);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame(['purged' => 2], $this->decode($r));

        $receipts = $this->c->get(ReceiptService::class);
        self::assertNotNull($receipts->find($shared), 'Beleg mit verbleibender Buchung bleibt');
        self::assertFileExists($this->dataDir . '/receipts/' . $shared);
        self::assertNull($receipts->find($single));
        self::assertFileDoesNotExist($this->dataDir . '/receipts/' . $single);
        self::assertNull($receipts->find($stale));
        self::assertFileDoesNotExist($this->dataDir . '/receipts/' . self::uuid(103));

        $pull = $this->decode($this->request('GET', '/sync?since=0', null, $this->token));
        sort($pull['purged']['receipts']);
        self::assertSame([$single, $stale], $pull['purged']['receipts']);
        self::assertSame(['purged' => 0], $this->decode($this->request('POST', '/trash/empty', null, $this->token)));
    }

    public function testFreshUnlinkedUploadSurvivesEmptyTrash(): void
    {
        $rid = self::uuid(200);
        $this->multipart('PUT', '/receipts/' . $rid, self::jpeg(), $this->token);
        $this->request('POST', '/trash/empty', null, $this->token);
        self::assertNotNull($this->c->get(ReceiptService::class)->find($rid), 'Karenzzeit fuer Offline-Abgleich');
    }
}
