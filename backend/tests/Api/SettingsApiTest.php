<?php

declare(strict_types=1);

namespace Quitt\Tests\Api;

use Quitt\Service\SettingsService;
use Quitt\Tests\ApiTestCase;

final class SettingsApiTest extends ApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->install();
        $this->token = $this->login();
    }

    public function testGetDefaults(): void
    {
        $d = $this->decode($this->request('GET', '/settings', null, $this->token));
        self::assertSame([
            'group_name' => 'Testkasse', 'read_without_pin' => false, 'recognition_mode' => 'local',
            'ai_provider' => null, 'ai_model' => null, 'ai_base_url' => null, 'ai_key_set' => false,
        ], $d);
        $this->assertError($this->request('GET', '/settings'), 401, 'unauthorized');
    }

    public function testPutPartialAndAiKeyWriteOnly(): void
    {
        $r = $this->request('PUT', '/settings', ['ai_provider' => 'mistral', 'recognition_mode' => 'ai', 'ai_key' => 'sk-geheim-123', 'ai_key_set' => false], $this->token);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $d = $this->decode($r);
        self::assertTrue($d['ai_key_set']);
        self::assertArrayNotHasKey('ai_key', $d);
        self::assertStringNotContainsString('sk-geheim', (string) $this->request('GET', '/settings', null, $this->token)->getBody());
        self::assertSame('Testkasse', $d['group_name'], 'teilweise Aenderung');
        self::assertSame('sk-geheim-123', $this->c->get(SettingsService::class)->get('ai_key'));

        $d = $this->decode($this->request('PUT', '/settings', ['ai_key' => ''], $this->token));
        self::assertFalse($d['ai_key_set']);
        self::assertSame('mistral', $d['ai_provider']);
    }

    public function testPutValidation(): void
    {
        $err = $this->assertError($this->request('PUT', '/settings', [
            'group_name' => '  ', 'read_without_pin' => 'ja', 'recognition_mode' => 'magic',
            'ai_provider' => 'skynet', 'ai_base_url' => 'ftp://x', 'farbe' => 'rot',
        ], $this->token), 422, 'validation_failed');
        self::assertSame(['group_name', 'read_without_pin', 'recognition_mode', 'ai_provider', 'ai_base_url', 'farbe'], array_keys($err['fields']));
        self::assertSame('Testkasse', $this->c->get(SettingsService::class)->get('group_name'), 'nichts gespeichert');
    }

    public function testPutRequiresPin(): void
    {
        $this->setReadWithoutPin(true);
        $this->assertError($this->request('PUT', '/settings', ['group_name' => 'X']), 403, 'pin_required');
        self::assertSame(200, $this->request('GET', '/settings')->getStatusCode());
    }

    public function testAiProviders(): void
    {
        $d = $this->decode($this->request('GET', '/settings/ai-providers', null, $this->token));
        $byId = array_column($d, null, 'id');
        self::assertSame(['mistral', 'openai', 'anthropic', 'gemini', 'ollama'], array_keys($byId));
        self::assertSame('mistral-small-latest', $byId['mistral']['default_model']);
        self::assertSame('gpt-4o-mini', $byId['openai']['default_model']);
        self::assertSame('claude-haiku-4-5', $byId['anthropic']['default_model']);
        self::assertSame('gemini-2.5-flash', $byId['gemini']['default_model']);
        self::assertSame('qwen2.5vl', $byId['ollama']['default_model']);
        self::assertTrue($byId['ollama']['needs_base_url']);
        self::assertFalse($byId['ollama']['needs_key']);
        $this->assertError($this->request('GET', '/settings/ai-providers'), 401, 'unauthorized');
    }
}
