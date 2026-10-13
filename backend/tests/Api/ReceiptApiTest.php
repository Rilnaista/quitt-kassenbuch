<?php

declare(strict_types=1);

namespace Quitt\Tests\Api;

use Quitt\Tests\ApiTestCase;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

final class ReceiptApiTest extends ApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->install();
        $this->token = $this->login();
    }

    public function testPutIsIdempotent(): void
    {
        $id = self::uuid(1);
        $r = $this->multipart('PUT', '/receipts/' . $id, self::jpeg(), $this->token);
        self::assertSame(201, $r->getStatusCode(), (string) $r->getBody());
        $d = $this->decode($r);
        self::assertSame(['id', 'mime', 'size', 'created_at', 'updated_at', 'deleted_at', 'version'], array_keys($d));
        self::assertSame('image/jpeg', $d['mime']);
        self::assertSame(strlen(self::jpeg()), $d['size']);

        $again = $this->multipart('PUT', '/receipts/' . $id, self::jpeg(), $this->token);
        self::assertSame(200, $again->getStatusCode());
        self::assertSame($d['version'], $this->decode($again)['version'], 'gleicher Inhalt -> unveraendert');

        $png = "\x89PNG\r\n\x1A\n" . str_repeat('p', 50);
        $replaced = $this->decode($this->multipart('PUT', '/receipts/' . $id, $png, $this->token));
        self::assertSame('image/png', $replaced['mime']);
        self::assertGreaterThan($d['version'], $replaced['version']);
    }

    public function testPutViaParsedUploadAndWebp(): void
    {
        // Pfad ueber $_FILES (z. B. Method-Override/POST-Parsing durch den Server)
        $webp = 'RIFF' . pack('V', 20) . 'WEBPVP8 ' . str_repeat("\0", 12);
        $stream = (new StreamFactory())->createStream($webp);
        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('PUT', 'http://localhost/api/v1/receipts/' . self::uuid(2))
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withUploadedFiles(['file' => new UploadedFile($stream, 'b.webp', 'image/webp', strlen($webp))]);
        $r = $this->app->handle($req);
        self::assertSame(201, $r->getStatusCode(), (string) $r->getBody());
        self::assertSame('image/webp', $this->decode($r)['mime']);
    }

    public function testPutRejectsInvalidInput(): void
    {
        $err = $this->assertError($this->multipart('PUT', '/receipts/' . self::uuid(1), '%PDF-1.4 kein Bild', $this->token), 422, 'validation_failed');
        self::assertSame('Nur Fotos im Format JPEG, PNG oder WebP sind erlaubt.', $err['fields']['file']);
        $this->assertError($this->multipart('PUT', '/receipts/' . self::uuid(1), self::jpeg(), $this->token, 'falsch'), 422, 'validation_failed');
        $this->assertError($this->multipart('PUT', '/receipts/nicht-uuid', self::jpeg(), $this->token), 422, 'validation_failed');
        $big = self::jpeg(str_repeat('z', 5 * 1024 * 1024));
        $err = $this->assertError($this->multipart('PUT', '/receipts/' . self::uuid(1), $big, $this->token), 422, 'validation_failed');
        self::assertSame('Die Datei ist größer als 5 MB.', $err['fields']['file']);
        $this->assertError($this->multipart('PUT', '/receipts/' . self::uuid(1), self::jpeg(), null), 401, 'unauthorized');
    }

    public function testGetFile(): void
    {
        $id = self::uuid(1);
        $this->multipart('PUT', '/receipts/' . $id, self::jpeg('inhalt'), $this->token);
        $r = $this->request('GET', '/receipts/' . $id . '/file', null, $this->token);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('image/jpeg', $r->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $r->getHeaderLine('Cache-Control'));
        self::assertSame(self::jpeg('inhalt'), (string) $r->getBody());

        $this->assertError($this->request('GET', '/receipts/' . $id . '/file'), 401, 'unauthorized');
        $this->assertError($this->request('GET', '/receipts/' . self::uuid(9) . '/file', null, $this->token), 404, 'not_found');

        $this->setReadWithoutPin(true);
        self::assertSame(200, $this->request('GET', '/receipts/' . $id . '/file')->getStatusCode());
    }
}
