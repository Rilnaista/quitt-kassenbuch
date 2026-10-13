<?php

declare(strict_types=1);

namespace Quitt\Tests\Api;

use Quitt\Recognition\HttpTransport;
use Quitt\Service\SettingsService;
use Quitt\Tests\ApiTestCase;
use Quitt\Tests\FakeTransport;

/** KI-Erkennung gegen die Slim-App mit gemocktem HTTP (REQ-010/011). */
final class RecognitionApiTest extends ApiTestCase
{
    private const SECRET = 'sk-streng-geheim-123';
    private const TEXT = "```json\n{\"date\":\"05.10.2026\",\"positions\":[{\"item\":\"Milch\",\"amount_cents\":\"1,29\"}],\"total_cents\":129}\n```";
    private FakeTransport $http;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->install();
        $this->token = $this->login();
        $this->http = new FakeTransport();
        $this->c->instance(HttpTransport::class, $this->http);
        self::assertSame(201, $this->multipart('PUT', '/receipts/' . self::uuid(1), self::jpeg(), $this->token)->getStatusCode());
    }

    private function configure(string $provider, array $more = []): void
    {
        $this->c->get(SettingsService::class)->update(['recognition_mode' => 'ai', 'ai_provider' => $provider, 'ai_key' => self::SECRET] + $more);
    }

    private function recognize(): \Psr\Http\Message\ResponseInterface
    {
        return $this->request('POST', '/receipts/' . self::uuid(1) . '/recognize', null, $this->token);
    }

    public function testDisabled(): void
    {
        $this->assertError($this->recognize(), 409, 'recognition_disabled');
        $this->c->get(SettingsService::class)->update(['recognition_mode' => 'ai']);
        $e = $this->assertError($this->recognize(), 409, 'recognition_disabled');
        self::assertStringContainsString('nicht vollständig', $e['message']);
    }

    public function testUnknownReceipt(): void
    {
        $this->configure('mistral');
        $this->assertError($this->request('POST', '/receipts/' . self::uuid(9) . '/recognize', null, $this->token), 404, 'not_found');
    }

    /** @dataProvider providers */
    public function testSuccessPerProvider(string $provider, string $urlPart, bool $keyInHeader): void
    {
        $this->configure($provider, $provider === 'ollama' ? ['ai_base_url' => 'http://10.0.0.5:11434'] : []);
        $this->http->push(200, FakeTransport::reply($provider, self::TEXT));
        $r = $this->recognize();
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        self::assertSame(
            ['date' => '2026-10-05', 'positions' => [['item' => 'Milch', 'amount_cents' => 129]], 'total_cents' => 129, 'provider' => $provider],
            $this->decode($r)
        );
        $req = $this->http->requests[0];
        self::assertStringContainsString($urlPart, $req['url']);
        self::assertSame($provider === 'ollama' ? 180 : 60, $req['timeout']);
        self::assertStringContainsString(base64_encode(self::jpeg()), $req['body']);
        if ($keyInHeader) {
            self::assertStringContainsString(self::SECRET, implode("\n", $req['headers']));
        }
        self::assertStringNotContainsString(self::SECRET, $req['url']);
        self::assertStringNotContainsString(self::SECRET, (string) $r->getBody());
    }

    public static function providers(): array
    {
        return [
            'mistral' => ['mistral', 'api.mistral.ai/v1/chat/completions', true],
            'openai' => ['openai', 'api.openai.com/v1/chat/completions', true],
            'anthropic' => ['anthropic', 'api.anthropic.com/v1/messages', true],
            'gemini' => ['gemini', 'models/gemini-2.5-flash:generateContent', true],
            'ollama' => ['ollama', '10.0.0.5:11434/api/chat', false],
        ];
    }

    /** @dataProvider failures */
    public function testErrorsPerProvider(string $provider, int $status, string $body, string $expected): void
    {
        $this->configure($provider, $provider === 'ollama' ? ['ai_base_url' => 'http://10.0.0.5:11434'] : []);
        $this->http->push($status, $body);
        $e = $this->assertError($this->recognize(), 502, 'provider_error');
        self::assertStringContainsString($expected, $e['message']);
        self::assertStringNotContainsString(self::SECRET, $e['message']);
    }

    public static function failures(): array
    {
        return [
            'mistral key' => ['mistral', 401, '{"message":"Unauthorized"}', 'Schlüssel'],
            'openai model' => ['openai', 404, '{"error":{"message":"The model does not exist"}}', 'Modell'],
            'anthropic key' => ['anthropic', 401, '{"error":{"type":"authentication_error"}}', 'Schlüssel'],
            'gemini key (400)' => ['gemini', 400, '{"error":{"message":"API key not valid."}}', 'Schlüssel'],
            'gemini model' => ['gemini', 404, '{"error":{"message":"models/x is not found"}}', 'Modell'],
            'ollama model' => ['ollama', 404, '{"error":"model \'x\' not found"}', 'Modell'],
            'openai rate' => ['openai', 429, '{}', 'Anfragelimit'],
            'mistral 500' => ['mistral', 503, 'down', 'Störung'],
            'anthropic garbage' => ['anthropic', 200, 'kein json', 'nicht lesbar'],
            'openai no json in text' => ['openai', 200, '{"choices":[{"message":{"content":"Tut mir leid."}}]}', 'nicht lesbar'],
        ];
    }

    public function testTransportErrors(): void
    {
        $this->configure('ollama', ['ai_base_url' => 'http://10.0.0.5:11434']);
        $this->http->fail('unreachable')->fail('timeout');
        self::assertStringContainsString('nicht erreichbar', $this->assertError($this->recognize(), 502, 'provider_error')['message']);
        self::assertStringContainsString('rechtzeitig', $this->assertError($this->recognize(), 502, 'provider_error')['message']);
    }

    public function testConnectionTest(): void
    {
        $this->configure('mistral');
        // gespeicherter Schluessel wird verwendet, Modell = Default
        $this->http->push(200, FakeTransport::reply('mistral', 'OK'));
        $r = $this->request('POST', '/settings/recognition/test', ['ai_provider' => 'mistral'], $this->token);
        $d = $this->decode($r);
        self::assertTrue($d['ok']);
        self::assertStringContainsString('mistral-small-latest', $d['message']);
        self::assertStringContainsString(self::SECRET, implode("\n", $this->http->requests[0]['headers']));
        self::assertStringNotContainsString('image', $this->http->requests[0]['body']);

        // eigener Schluessel aus dem Formular hat Vorrang; Fehler -> 200 ok:false
        $this->http->push(401, '{}');
        $d = $this->decode($this->request('POST', '/settings/recognition/test', ['ai_provider' => 'openai', 'ai_key' => 'neu-123', 'ai_model' => 'gpt-x'], $this->token));
        self::assertFalse($d['ok']);
        self::assertStringContainsString('Schlüssel', $d['message']);
        self::assertStringContainsString('neu-123', implode("\n", $this->http->requests[1]['headers']));
        self::assertStringContainsString('gpt-x', $this->http->requests[1]['body']);
    }

    public function testConnectionTestValidation(): void
    {
        $this->assertError($this->request('POST', '/settings/recognition/test', ['ai_provider' => 'foo'], $this->token), 422, 'validation_failed');
        $d = $this->decode($this->request('POST', '/settings/recognition/test', ['ai_provider' => 'gemini'], $this->token));
        self::assertFalse($d['ok']);
        $d = $this->decode($this->request('POST', '/settings/recognition/test', ['ai_provider' => 'ollama'], $this->token));
        self::assertFalse($d['ok']);
        self::assertStringContainsString('Serveradresse', $d['message']);
        self::assertSame([], $this->http->requests);
    }

    public function testRequiresAuth(): void
    {
        $this->assertError($this->request('POST', '/settings/recognition/test', ['ai_provider' => 'mistral']), 401, 'unauthorized');
    }
}
