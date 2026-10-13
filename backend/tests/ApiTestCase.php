<?php

declare(strict_types=1);

namespace Quitt\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Quitt\App\Bootstrap;
use Quitt\Service\InstallService;
use Quitt\Service\SettingsService;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/** HTTP-Tests gegen die echte Slim-App (in-process, Basis-Pfad ""). */
abstract class ApiTestCase extends TestCase
{
    use TestHelpers;

    protected App $app;
    protected ?string $recoveryCode = null;

    protected function setUp(): void
    {
        $this->bootContainer();
        $this->app = Bootstrap::app($this->c, '');
    }

    protected function tearDown(): void
    {
        $this->c->get(\Quitt\Db\Database::class)->close();
        $this->cleanupDataDir();
    }

    protected function install(string $pin = '1234'): void
    {
        $this->recoveryCode = $this->c->get(InstallService::class)->install('Testkasse', $pin);
    }

    protected function login(string $pin = '1234'): string
    {
        $r = $this->request('POST', '/auth/login', ['pin' => $pin]);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        return $this->decode($r)['token'];
    }

    protected function setReadWithoutPin(bool $on): void
    {
        $this->c->get(SettingsService::class)->set('read_without_pin', $on);
    }

    /**
     * @param array|null $json Body als JSON
     * @param string|null $raw roher Body (ueberschreibt $json)
     */
    protected function request(string $method, string $path, ?array $json = null, ?string $token = null, array $headers = [], ?string $raw = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api/v1' . $path);
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        foreach ($headers as $k => $v) {
            $request = $request->withHeader($k, $v);
        }
        if ($raw !== null || $json !== null) {
            $body = $raw ?? json_encode($json, JSON_UNESCAPED_UNICODE);
            $request = $request->withBody((new StreamFactory())->createStream($body));
            if ($raw === null) {
                $request = $request->withHeader('Content-Type', 'application/json');
            }
        }
        return $this->app->handle($request);
    }

    protected function decode(ResponseInterface $r): array
    {
        $data = json_decode((string) $r->getBody(), true);
        self::assertIsArray($data, 'Antwort ist kein JSON: ' . $r->getBody());
        return $data;
    }

    protected function assertError(ResponseInterface $r, int $status, string $code): array
    {
        self::assertSame($status, $r->getStatusCode(), (string) $r->getBody());
        $data = $this->decode($r);
        self::assertSame($code, $data['error']['code'] ?? null);
        self::assertIsString($data['error']['message']);
        self::assertNotSame('', $data['error']['message']);
        self::assertStringNotContainsString('error.', $data['error']['message'], 'Meldung fehlt in lang/de.php');
        return $data['error'];
    }

    /** multipart/form-data mit einem Datei-Feld */
    protected function multipart(string $method, string $path, string $bytes, ?string $token, string $field = 'file'): ResponseInterface
    {
        $boundary = '----quitt' . bin2hex(random_bytes(6));
        $body = "--$boundary\r\nContent-Disposition: form-data; name=\"$field\"; filename=\"beleg.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n"
            . $bytes . "\r\n--$boundary--\r\n";
        return $this->request($method, $path, null, $token, ['Content-Type' => 'multipart/form-data; boundary=' . $boundary], $body);
    }
}
