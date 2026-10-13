<?php

declare(strict_types=1);

namespace Quitt\Tests;

use Quitt\Update\HttpClient;

/** HTTP-Attrappe fuer Update-Tests: feste Antworten je URL, Downloads aus lokalen Dateien. */
final class FakeHttpClient implements HttpClient
{
    /** @var array<string, array{status: int, body: string}|\RuntimeException> */
    public array $routes = [];
    /** @var list<string> */
    public array $calls = [];

    public function on(string $url, int $status, string|array $body): self
    {
        $this->routes[$url] = ['status' => $status, 'body' => is_array($body) ? json_encode($body) : $body];
        return $this;
    }

    public function failOn(string $url): self
    {
        $this->routes[$url] = new \RuntimeException('Server nicht erreichbar');
        return $this;
    }

    public function get(string $url, array $headers, int $timeoutSeconds): array
    {
        $this->calls[] = $url;
        $r = $this->routes[$url] ?? ['status' => 404, 'body' => ''];
        if ($r instanceof \RuntimeException) {
            throw $r;
        }
        return $r;
    }

    public function download(string $url, array $headers, string $target, int $timeoutSeconds): int
    {
        $r = $this->get($url, $headers, $timeoutSeconds);
        file_put_contents($target, $r['body']);
        return $r['status'];
    }
}
