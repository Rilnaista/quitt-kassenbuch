<?php

declare(strict_types=1);

namespace Quitt\Tests\Api;

use Quitt\App\Bootstrap;
use Quitt\Tests\ApiTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SystemApiTest extends ApiTestCase
{
    public function testHealth(): void
    {
        $r = $this->request('GET', '/health');
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('no-store', $r->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('charset=utf-8', $r->getHeaderLine('Content-Type'));
        $d = $this->decode($r);
        self::assertTrue($d['ok']);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $d['version']);
    }

    public function testSystemInfoBeforeAndAfterInstall(): void
    {
        $d = $this->decode($this->request('GET', '/system/info'));
        self::assertFalse($d['installed']);
        self::assertSame('Unsere Kasse', $d['group_name']);
        self::assertSame('/', $d['base_path']);
        self::assertFalse($d['https']);
        self::assertSame(['version', 'installed', 'https', 'docker', 'base_path', 'read_without_pin', 'group_name'], array_keys($d));

        $this->install();
        $d = $this->decode($this->request('GET', '/system/info', null, null, ['X-Forwarded-Proto' => 'https']));
        self::assertTrue($d['installed']);
        self::assertTrue($d['https']);
        self::assertSame('Testkasse', $d['group_name']);
    }

    public function testBasePathInSubfolder(): void
    {
        $app = Bootstrap::app($this->c, '/kasse');
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/kasse/api/v1/system/info');
        $d = json_decode((string) $app->handle($req)->getBody(), true);
        self::assertSame('/kasse/', $d['base_path']);
    }

    public function testProtectedEndpointsReturn503BeforeInstall(): void
    {
        $this->assertError($this->request('GET', '/sync'), 503, 'not_installed');
        $this->assertError($this->request('POST', '/auth/login', ['pin' => '1234']), 503, 'not_installed');
    }

    public function testOpenApiServed(): void
    {
        $r = $this->request('GET', '/openapi.json');
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('3.0.3', $this->decode($r)['openapi']);
    }

    public function testUnknownRouteUsesErrorFormat(): void
    {
        $r = $this->request('GET', '/gibt-es-nicht');
        $this->assertError($r, 404, 'not_found');
        self::assertSame('no-store', $r->getHeaderLine('Cache-Control'));
        $this->assertError($this->request('PATCH', '/health'), 405, 'method_not_allowed');
    }

    public function testInvalidJsonIs400(): void
    {
        $this->install();
        $this->assertError($this->request('POST', '/auth/login', null, null, ['Content-Type' => 'application/json'], '{kaputt'), 400, 'bad_request');
    }

    public function testDataDirIsProtected(): void
    {
        self::assertFileExists($this->dataDir . '/.htaccess');
        self::assertStringContainsString('Require all denied', (string) file_get_contents($this->dataDir . '/.htaccess'));
        foreach (['', '/receipts', '/logs', '/backups'] as $sub) {
            self::assertFileExists($this->dataDir . $sub . '/index.php');
        }
    }
}
