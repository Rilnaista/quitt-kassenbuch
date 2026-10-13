<?php

declare(strict_types=1);

namespace Quitt\Tests;

use Quitt\Recognition\HttpTransport;
use Quitt\Recognition\ProviderException;

/** HTTP-Attrappe: liefert vorbereitete Antworten (oder wirft ProviderException) und merkt sich Anfragen. */
final class FakeTransport implements HttpTransport
{
    /** @var list<array{url: string, headers: array, body: string, timeout: int}> */
    public array $requests = [];
    /** @var list<array{status: int, body: string}|ProviderException> */
    private array $queue = [];

    public function push(int $status, string|array $body): self
    {
        $this->queue[] = ['status' => $status, 'body' => is_array($body) ? json_encode($body) : $body];
        return $this;
    }

    public function fail(string $kind): self
    {
        $this->queue[] = new ProviderException($kind);
        return $this;
    }

    public function post(string $url, array $headers, string $body, int $timeoutSeconds): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeoutSeconds];
        $next = array_shift($this->queue) ?? throw new \LogicException('Keine Antwort vorbereitet');
        if ($next instanceof ProviderException) {
            throw $next;
        }
        return $next;
    }

    /** Antwort im Format des Anbieters mit dem Modelltext. */
    public static function reply(string $provider, string $text): array
    {
        return match ($provider) {
            'mistral', 'openai' => ['choices' => [['message' => ['content' => $text]]]],
            'anthropic' => ['content' => [['type' => 'text', 'text' => $text]]],
            'gemini' => ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]],
            'ollama' => ['message' => ['content' => $text]],
        };
    }
}
