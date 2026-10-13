<?php

declare(strict_types=1);

namespace Quitt\Tests\Api;

use Quitt\Tests\ApiTestCase;

final class ClientLogApiTest extends ApiTestCase
{
    private function logLines(): array
    {
        $out = [];
        foreach (glob($this->dataDir . '/logs/*.log') as $f) {
            foreach (file($f) as $l) {
                $out[] = json_decode($l, true);
            }
        }
        return $out;
    }

    public function testStoresMaskedEntryWithoutAuth(): void
    {
        $r = $this->request('POST', '/logs/client', ['level' => 'error', 'message' => 'Sync fehlgeschlagen', 'context' => ['status' => 500, 'token' => 'geheim']]);
        self::assertSame(204, $r->getStatusCode());
        $client = array_values(array_filter($this->logLines(), fn ($l) => $l['channel'] === 'client'));
        self::assertCount(1, $client);
        self::assertSame('error', $client[0]['level']);
        self::assertSame('Sync fehlgeschlagen', $client[0]['message']);
        self::assertSame('***', $client[0]['context']['token']);
    }

    public function testValidation(): void
    {
        $err = $this->assertError($this->request('POST', '/logs/client', ['level' => 'panic']), 422, 'validation_failed');
        self::assertSame(['level', 'message'], array_keys($err['fields']));
    }

    public function testThrottled(): void
    {
        for ($i = 0; $i < 40; $i++) {
            self::assertSame(204, $this->request('POST', '/logs/client', ['level' => 'info', 'message' => 'm' . $i])->getStatusCode());
        }
        $client = array_filter($this->logLines(), fn ($l) => $l['channel'] === 'client');
        self::assertCount(30, $client);
        $this->clock->advance(60);
        $this->request('POST', '/logs/client', ['level' => 'info', 'message' => 'neue Minute']);
        self::assertCount(31, array_filter($this->logLines(), fn ($l) => $l['channel'] === 'client'));
    }
}
