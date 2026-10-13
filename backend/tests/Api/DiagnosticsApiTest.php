<?php

declare(strict_types=1);

namespace Quitt\Tests\Api;

use Quitt\Tests\ApiTestCase;

final class DiagnosticsApiTest extends ApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->install('4711');
        $this->token = $this->login('4711');
    }

    public function testLogsNewestFirstFilteredAndLimited(): void
    {
        $log = $this->c->get(\Quitt\Log\Logger::class);
        $log->info('eins');
        $this->clock->advance(1);
        $log->warning('zwei');
        $this->clock->advance(1);
        $log->error('drei', ['code' => 7]);
        // Vormonat
        $this->clock->advance(40 * 86400);
        $log->info('vier');

        $all = $this->decode($this->request('GET', '/logs?limit=500', null, $this->token));
        $msgs = array_column($all, 'message');
        self::assertSame('vier', $msgs[0]);
        self::assertSame(['vier', 'drei', 'zwei', 'eins'], array_slice($msgs, 0, 4));
        self::assertSame(['ts', 'level', 'channel', 'message', 'context'], array_keys($all[0]));

        $err = $this->decode($this->request('GET', '/logs?level=error', null, $this->token));
        self::assertSame(['drei'], array_column($err, 'message'));
        self::assertSame(7, $err[0]['context']['code']);
        $warn = $this->decode($this->request('GET', '/logs?level=warning', null, $this->token));
        self::assertSame(['drei', 'zwei'], array_column($warn, 'message'));
        self::assertCount(2, $this->decode($this->request('GET', '/logs?limit=2', null, $this->token)));
    }

    public function testLogsValidationAndAuth(): void
    {
        $this->assertError($this->request('GET', '/logs?level=debug', null, $this->token), 422, 'validation_failed');
        $this->assertError($this->request('GET', '/logs?limit=abc', null, $this->token), 422, 'validation_failed');
        $this->assertError($this->request('GET', '/logs'), 401, 'unauthorized');
        $this->setReadWithoutPin(true);
        $this->assertError($this->request('GET', '/logs'), 401, 'unauthorized');
    }

    public function testDiagnosticsFormat(): void
    {
        $r = $this->request('GET', '/diagnostics', null, $this->token);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('application/json', $r->getHeaderLine('Content-Type'));
        self::assertMatchesRegularExpression('/quitt-diagnose-\d{4}-\d{2}-\d{2}\.json/', $r->getHeaderLine('Content-Disposition'));
        $d = $this->decode($r);
        self::assertSame('quitt-diagnostics/1', $d['format']);
        foreach (['version', 'php', 'sapi', 'os', 'modules', 'base_path', 'https', 'docker', 'disk_free', 'db_size', 'counts', 'settings'] as $k) {
            self::assertArrayHasKey($k, $d['meta'], $k);
        }
        self::assertIsInt($d['meta']['disk_free']);
        self::assertIsInt($d['meta']['db_size']);
        self::assertGreaterThan(0, $d['meta']['db_size']);
        self::assertFalse($d['meta']['https']);
        self::assertSame('', $d['meta']['base_path']);
        self::assertIsArray($d['logs']);
        $names = array_column($d['checks'], 'name');
        foreach (['PHP-Version (ab 8.1)', 'Datenordner beschreibbar', 'Verschlüsselte Verbindung (HTTPS)', 'Freier Speicher'] as $n) {
            self::assertContains($n, $names);
        }
        foreach ($d['checks'] as $c) {
            self::assertIsBool($c['ok']);
            self::assertIsString($c['detail']);
            self::assertStringNotContainsString('diag.', $c['name'] . $c['detail'], 'Text fehlt in lang/de.php');
        }
        $https = array_values(array_filter($d['checks'], fn ($c) => str_contains($c['name'], 'HTTPS')))[0];
        self::assertFalse($https['ok']);
    }

    public function testDiagnosticsNeverContainsSecrets(): void
    {
        $this->c->get(\Quitt\Service\SettingsService::class)->set('ai_key', 'sk-GEHEIMER-KI-SCHLUESSEL');
        $log = $this->c->get(\Quitt\Log\Logger::class);
        $log->error('Test', ['ai_key' => 'sk-GEHEIMER-KI-SCHLUESSEL', 'token' => 'TOKEN-KLARTEXT-1', 'ctx' => ['recovery_code' => 'ABCD-EFGH-IJKL', 'pin' => '4711']]);
        $log->error('Authorization: Bearer TOKEN-KLARTEXT-2 fehlgeschlagen');
        // Altlast: ungemaskierte Zeile direkt in der Logdatei
        $file = glob($this->dataDir . '/logs/quitt-*.log')[0];
        file_put_contents($file, json_encode(['ts' => '2026-10-13T08:00:00.000Z', 'level' => 'error', 'channel' => 'x', 'message' => 'alt', 'context' => ['token' => 'TOKEN-KLARTEXT-3', 'pin_hash' => 'HASH-ALT']]) . "\n", FILE_APPEND);

        $body = (string) $this->request('GET', '/diagnostics', null, $this->token)->getBody();
        $meta = $this->c->get(\Quitt\Db\Meta::class);
        $forbidden = [
            $meta->get('pin_hash'), $meta->get('recovery_hash'), $this->recoveryCode, $this->token,
            'sk-GEHEIMER-KI-SCHLUESSEL', 'TOKEN-KLARTEXT-1', 'TOKEN-KLARTEXT-2', 'TOKEN-KLARTEXT-3', 'ABCD-EFGH-IJKL', 'HASH-ALT',
            hash('sha256', $this->token),
        ];
        foreach ($this->c->get(\Quitt\Db\Database::class)->all('SELECT token_hash FROM auth_tokens') as $row) {
            $forbidden[] = $row['token_hash'];
        }
        foreach ($forbidden as $secret) {
            self::assertNotEmpty($secret);
            self::assertStringNotContainsString((string) $secret, $body);
        }
        self::assertStringContainsString('"ai_key_set": true', $body);
    }

    public function testDiagnosticsNeedsAuthAndWorksViaTicket(): void
    {
        $this->assertError($this->request('GET', '/diagnostics'), 401, 'unauthorized');
        $url = $this->decode($this->request('POST', '/downloads', ['target' => 'diagnostics'], $this->token))['url'];
        $r = $this->request('GET', substr($url, strlen('api/v1')));
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('quitt-diagnostics/1', $this->decode($r)['format']);
        $this->assertError($this->request('GET', substr($url, strlen('api/v1'))), 401, 'unauthorized');
    }
}
