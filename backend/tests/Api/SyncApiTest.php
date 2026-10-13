<?php

declare(strict_types=1);

namespace Quitt\Tests\Api;

use Quitt\Tests\ApiTestCase;

final class SyncApiTest extends ApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->install();
        $this->token = $this->login();
    }

    public function testPushAndPull(): void
    {
        $r = $this->request('POST', '/sync', ['entries' => [self::entry(1), self::entry(2, ['type' => 'in', 'purpose' => 'Beitrag Müller'])]], $this->token);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $d = $this->decode($r);
        self::assertCount(2, $d['applied']);
        self::assertSame([], $d['conflicts']);
        self::assertSame('Beitrag Müller', $d['applied'][1]['purpose']);

        $pull = $this->decode($this->request('GET', '/sync?since=0', null, $this->token));
        self::assertSame(['entries', 'receipts', 'purged', 'cursor', 'server_time'], array_keys($pull));
        self::assertCount(2, $pull['entries']);
        self::assertSame(['entries' => [], 'receipts' => []], $pull['purged']);
        self::assertSame($d['cursor'], $pull['cursor']);

        $empty = $this->decode($this->request('GET', '/sync?since=' . $pull['cursor'], null, $this->token));
        self::assertSame([], $empty['entries']);
    }

    public function testValidationErrorsPerId(): void
    {
        $r = $this->request('POST', '/sync', ['entries' => [self::entry(1, ['date' => '2026-02-30', 'amount_cents' => -5])]], $this->token);
        $err = $this->assertError($r, 422, 'validation_failed');
        $f = $err['fields'][self::uuid(1)];
        self::assertSame(['date', 'amount_cents'], array_keys($f));
        self::assertSame('Bitte ein gültiges Datum angeben.', $f['date'], 'Feldmeldungen sind uebersetzt');

        $this->assertError($this->request('POST', '/sync', ['entries' => [self::entry(1, ['receipt_id' => self::uuid(9)])]], $this->token), 422, 'validation_failed');
        $this->assertError($this->request('POST', '/sync', ['entries' => 'x'], $this->token), 422, 'validation_failed');
        $this->assertError($this->request('GET', '/sync?since=abc', null, $this->token), 422, 'validation_failed');
    }

    public function testMaxBatchSize(): void
    {
        $many = [];
        for ($i = 1; $i <= 501; $i++) {
            $many[] = self::entry($i);
        }
        $this->assertError($this->request('POST', '/sync', ['entries' => $many], $this->token), 422, 'validation_failed');
        self::assertSame(200, $this->request('POST', '/sync', ['entries' => array_slice($many, 0, 500)], $this->token)->getStatusCode());
    }

    public function testReadWithoutPin(): void
    {
        $this->assertError($this->request('GET', '/sync'), 401, 'unauthorized');
        $this->assertError($this->request('POST', '/sync', ['entries' => []]), 401, 'unauthorized');

        $this->setReadWithoutPin(true);
        self::assertSame(200, $this->request('GET', '/sync')->getStatusCode());
        $this->assertError($this->request('POST', '/sync', ['entries' => [self::entry(1)]]), 403, 'pin_required');
        $this->assertError($this->request('GET', '/sync', null, 'abgelaufen'), 401, 'unauthorized');
    }
}
