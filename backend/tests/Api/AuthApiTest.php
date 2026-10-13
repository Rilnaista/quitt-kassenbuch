<?php

declare(strict_types=1);

namespace Quitt\Tests\Api;

use Quitt\Tests\ApiTestCase;

final class AuthApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->install('1234');
    }

    public function testLoginSuccessAndWrongPin(): void
    {
        $r = $this->request('POST', '/auth/login', ['pin' => '1234']);
        self::assertSame(200, $r->getStatusCode());
        $d = $this->decode($r);
        self::assertGreaterThan(30, strlen($d['token']));
        self::assertSame('2027-10-13T08:00:00.000Z', $d['expires_at'], '365 Tage');

        $this->assertError($this->request('POST', '/auth/login', ['pin' => '9999']), 401, 'unauthorized');
    }

    public function testLoginRateLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertError($this->request('POST', '/auth/login', ['pin' => '0000']), 401, 'unauthorized');
        }
        $r = $this->request('POST', '/auth/login', ['pin' => '1234']);
        $err = $this->assertError($r, 429, 'rate_limited');
        self::assertSame(30, $err['retry_after']);
        self::assertSame('30', $r->getHeaderLine('Retry-After'));
        self::assertStringContainsString('30 Sekunden', $err['message']);

        $this->clock->advance(31);
        self::assertSame(200, $this->request('POST', '/auth/login', ['pin' => '1234'])->getStatusCode());
    }

    public function testTokenSlidingExpiry(): void
    {
        $t = $this->login();
        $this->clock->advance(300 * 86400);
        self::assertSame(200, $this->request('GET', '/sync', null, $t)->getStatusCode(), 'Nutzung verlaengert');
        $this->clock->advance(300 * 86400);
        self::assertSame(200, $this->request('GET', '/sync', null, $t)->getStatusCode());
        $this->clock->advance(366 * 86400);
        $this->assertError($this->request('GET', '/sync', null, $t), 401, 'unauthorized');
    }

    public function testStatus(): void
    {
        $d = $this->decode($this->request('GET', '/auth/status'));
        self::assertSame(['authenticated' => false, 'read_without_pin' => false], $d);
        $d = $this->decode($this->request('GET', '/auth/status', null, 'ungueltig'));
        self::assertFalse($d['authenticated'], 'ungueltiges Token -> false statt 401');
        $d = $this->decode($this->request('GET', '/auth/status', null, $this->login()));
        self::assertTrue($d['authenticated']);
    }

    public function testLogout(): void
    {
        $t = $this->login();
        self::assertSame(204, $this->request('POST', '/auth/logout', null, $t)->getStatusCode());
        $this->assertError($this->request('GET', '/sync', null, $t), 401, 'unauthorized');
        $this->assertError($this->request('POST', '/auth/logout'), 401, 'unauthorized');
    }

    public function testChangePin(): void
    {
        $t = $this->login();
        $other = $this->login();
        $err = $this->assertError($this->request('POST', '/auth/pin', ['old_pin' => '0000', 'new_pin' => '5678'], $t), 422, 'validation_failed');
        self::assertArrayHasKey('old_pin', $err['fields']);
        $err = $this->assertError($this->request('POST', '/auth/pin', ['old_pin' => '1234', 'new_pin' => '12'], $t), 422, 'validation_failed');
        self::assertArrayHasKey('new_pin', $err['fields']);

        self::assertSame(204, $this->request('POST', '/auth/pin', ['old_pin' => '1234', 'new_pin' => '56789012'], $t)->getStatusCode());
        $this->assertError($this->request('POST', '/auth/login', ['pin' => '1234']), 401, 'unauthorized');
        self::assertSame(200, $this->request('POST', '/auth/login', ['pin' => '56789012'])->getStatusCode());
        self::assertSame(200, $this->request('GET', '/sync', null, $other)->getStatusCode(), 'andere Tokens bleiben gueltig');
    }

    public function testResetInfo(): void
    {
        self::assertSame(['reset_file_present' => false], $this->decode($this->request('GET', '/auth/reset')));
        touch($this->dataDir . '/reset-pin');
        self::assertSame(['reset_file_present' => true], $this->decode($this->request('GET', '/auth/reset')));
    }

    public function testResetWithRecoveryCode(): void
    {
        $old = $this->login();
        $this->assertError($this->request('POST', '/auth/reset', ['recovery_code' => 'AAAA-BBBB-CCCC-DDDD', 'new_pin' => '4321']), 401, 'unauthorized');

        $code = strtolower(str_replace('-', ' ', $this->recoveryCode)); // tolerant bei Schreibweise
        $r = $this->request('POST', '/auth/reset', ['recovery_code' => $code, 'new_pin' => '4321']);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $d = $this->decode($r);
        self::assertMatchesRegularExpression('/^[A-Z0-9]{4}(-[A-Z0-9]{4}){3}$/', $d['recovery_code']);
        self::assertNotSame($this->recoveryCode, $d['recovery_code']);
        self::assertSame(200, $this->request('GET', '/sync', null, $d['token'])->getStatusCode());
        $this->assertError($this->request('GET', '/sync', null, $old), 401, 'unauthorized');
        self::assertSame(200, $this->request('POST', '/auth/login', ['pin' => '4321'])->getStatusCode());

        // alter Code ist verbraucht
        $this->assertError($this->request('POST', '/auth/reset', ['recovery_code' => $this->recoveryCode, 'new_pin' => '1111']), 401, 'unauthorized');
    }

    public function testResetWithFile(): void
    {
        touch($this->dataDir . '/reset-pin');
        $this->assertError($this->request('POST', '/auth/reset', ['new_pin' => 'abc']), 422, 'validation_failed');
        self::assertFileExists($this->dataDir . '/reset-pin', 'bei Fehler bleibt Datei');
        $r = $this->request('POST', '/auth/reset', ['new_pin' => '2468']);
        self::assertSame(200, $r->getStatusCode());
        self::assertFileDoesNotExist($this->dataDir . '/reset-pin');
        $this->assertError($this->request('POST', '/auth/reset', ['new_pin' => '1357']), 401, 'unauthorized');
    }

    public function testResetIsRateLimited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->request('POST', '/auth/reset', ['recovery_code' => 'FALSCH', 'new_pin' => '4321']);
        }
        $this->assertError($this->request('POST', '/auth/reset', ['recovery_code' => $this->recoveryCode, 'new_pin' => '4321']), 429, 'rate_limited');
    }

    public function testSecretsNeverLogged(): void
    {
        $this->request('POST', '/auth/login', ['pin' => '1234']);
        $this->request('POST', '/auth/login', ['pin' => '9876']);
        $log = implode('', array_map('file_get_contents', glob($this->dataDir . '/logs/*.log')));
        self::assertStringNotContainsString('9876', $log);
        self::assertStringNotContainsString($this->recoveryCode, $log);
    }
}
