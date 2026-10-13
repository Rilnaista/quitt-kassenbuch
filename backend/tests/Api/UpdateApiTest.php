<?php

declare(strict_types=1);

namespace Quitt\Tests\Api;

use Quitt\Tests\ApiTestCase;
use Quitt\Tests\FakeHttpClient;
use Quitt\Update\HttpClient;

final class UpdateApiTest extends ApiTestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttpClient();
        $this->c->instance(HttpClient::class, $this->http);
    }

    public function testCheckNeedsLoginAndReportsRelease(): void
    {
        $this->install();
        $this->assertError($this->request('GET', '/update/check'), 401, 'unauthorized');
        $this->setReadWithoutPin(true);
        $this->assertError($this->request('GET', '/update/check'), 403, 'pin_required');

        $this->http->on('https://api.github.com/repos/Rilnaista/quitt-kassenbuch/releases?per_page=30', 200, [[
            'tag_name' => 'v99.0.0-rc1', 'prerelease' => true, 'body' => 'Notizen', 'published_at' => '2026-10-12T10:00:00Z',
            'assets' => [
                ['name' => 'quitt-99.0.0-rc1.zip', 'browser_download_url' => 'https://example.invalid/q.zip'],
                ['name' => 'quitt-99.0.0-rc1.zip.sha256', 'browser_download_url' => 'https://example.invalid/q.zip.sha256'],
            ],
        ]]);
        $r = $this->request('GET', '/update/check', null, $this->login());
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $d = $this->decode($r);
        self::assertSame(['current', 'latest', 'available', 'notes', 'published_at', 'docker'], array_keys($d));
        self::assertSame('99.0.0-rc1', $d['latest']);
        self::assertTrue($d['available']);
    }

    public function testForceQueryBypassesCache(): void
    {
        $this->install();
        // altes Ergebnis (1 h alt, also noch innerhalb der 6 h) im Cache
        file_put_contents($this->c->get(\Quitt\App\Config::class)->path('update-check.json'), json_encode([
            'checked_at' => $this->clock->now()->getTimestamp() - 3600, 'error' => false,
            'release' => ['version' => '99.0.0', 'notes' => 'alt', 'published_at' => '2026-10-01T00:00:00Z'],
        ]));
        $this->http->on('https://api.github.com/repos/Rilnaista/quitt-kassenbuch/releases?per_page=30', 200, [[
            'tag_name' => 'v99.0.1-rc1', 'prerelease' => true, 'body' => 'frisch', 'published_at' => '2026-10-12T10:00:00Z',
            'assets' => [
                ['name' => 'quitt-99.0.1-rc1.zip', 'browser_download_url' => 'https://example.invalid/q.zip'],
                ['name' => 'quitt-99.0.1-rc1.zip.sha256', 'browser_download_url' => 'https://example.invalid/q.zip.sha256'],
            ],
        ]]);
        $cached = $this->decode($this->request('GET', '/update/check', null, $this->login()));
        self::assertSame('99.0.0', $cached['latest']);
        self::assertSame([], $this->http->calls, 'ohne force kein Abruf');

        $fresh = $this->decode($this->request('GET', '/update/check?force=1', null, $this->login()));
        self::assertSame('99.0.1-rc1', $fresh['latest']);
        self::assertCount(1, $this->http->calls, 'force=1 umgeht den Cache');
    }

    public function testApplyNeedsPinAndFailsOutsideReleaseLayout(): void
    {
        $this->install();
        $this->assertError($this->request('POST', '/update/apply'), 401, 'unauthorized');
        // Testumgebung = Entwicklungslayout -> verweigert, ohne etwas anzufassen
        $e = $this->assertError($this->request('POST', '/update/apply', null, $this->login()), 500, 'update_failed');
        self::assertStringContainsString('Installationspaket', $e['message']);
        self::assertSame([], $this->http->calls);
    }

    public function testApplyInDockerIs409(): void
    {
        putenv('QUITT_DOCKER=1');
        try {
            $this->install();
            $this->assertError($this->request('POST', '/update/apply', null, $this->login()), 409, 'docker_managed');
            $d = $this->decode($this->request('GET', '/update/check', null, $this->login()));
            self::assertTrue($d['docker']);
            self::assertFalse($d['available'], 'Abruffehler bleiben still');
        } finally {
            putenv('QUITT_DOCKER');
        }
    }
}
