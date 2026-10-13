<?php

declare(strict_types=1);

namespace Quitt\Tests\Api;

use Quitt\Tests\ApiTestCase;

final class DownloadApiTest extends ApiTestCase
{
    private string $token;
    private string $rid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->install();
        $this->token = $this->login();
        $this->rid = self::uuid(1);
        $this->multipart('PUT', '/receipts/' . $this->rid, self::jpeg(), $this->token);
    }

    private function fetch(string $url): \Psr\Http\Message\ResponseInterface
    {
        self::assertStringStartsWith('api/v1/', $url, 'relativ zu <base>');
        return $this->request('GET', substr($url, strlen('api/v1')));
    }

    public function testReceiptTicketIsSingleUse(): void
    {
        $r = $this->request('POST', '/downloads', ['target' => 'receipt', 'params' => ['id' => $this->rid]], $this->token);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $url = $this->decode($r)['url'];
        self::assertMatchesRegularExpression('#^api/v1/receipts/' . $this->rid . '/file\?ticket=[0-9a-f]{48}$#', $url);

        $first = $this->fetch($url);
        self::assertSame(200, $first->getStatusCode());
        self::assertSame('image/jpeg', $first->getHeaderLine('Content-Type'));
        $this->assertError($this->fetch($url), 401, 'unauthorized');
    }

    public function testTicketExpiresAndIsBoundToPath(): void
    {
        $url = $this->decode($this->request('POST', '/downloads', ['target' => 'receipt', 'params' => ['id' => $this->rid]], $this->token))['url'];
        $other = self::uuid(2);
        $this->multipart('PUT', '/receipts/' . $other, self::jpeg('x'), $this->token);
        $this->assertError($this->fetch(str_replace($this->rid, $other, $url)), 401, 'unauthorized');

        $url2 = $this->decode($this->request('POST', '/downloads', ['target' => 'receipt', 'params' => ['id' => $this->rid]], $this->token))['url'];
        $this->clock->advance(121);
        $this->assertError($this->fetch($url2), 401, 'unauthorized');
    }

    public function testInvalidRequests(): void
    {
        $this->assertError($this->request('POST', '/downloads', ['target' => 'pdf'], $this->token), 422, 'validation_failed');
        $this->assertError($this->request('POST', '/downloads', ['target' => 'receipt', 'params' => ['id' => 'x']], $this->token), 422, 'validation_failed');
        $this->assertError($this->request('POST', '/downloads', ['target' => 'receipt', 'params' => ['id' => self::uuid(5)]], $this->token), 404, 'not_found');
        $this->assertError($this->request('POST', '/downloads', ['target' => 'receipt', 'params' => ['id' => $this->rid]]), 401, 'unauthorized');
    }

    public function testReadWithoutPinAllowsReadTargets(): void
    {
        $this->setReadWithoutPin(true);
        $r = $this->request('POST', '/downloads', ['target' => 'receipt', 'params' => ['id' => $this->rid]]);
        self::assertSame(200, $r->getStatusCode());
    }
}
