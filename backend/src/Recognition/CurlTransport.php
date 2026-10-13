<?php

declare(strict_types=1);

namespace Quitt\Recognition;

/** HTTP per curl, Fallback auf Streams (allow_url_fopen), wenn die curl-Extension fehlt. */
final class CurlTransport implements HttpTransport
{
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): array
    {
        return function_exists('curl_init')
            ? $this->viaCurl($url, $headers, $body, $timeoutSeconds)
            : $this->viaStream($url, $headers, $body, $timeoutSeconds);
    }

    private function viaCurl(string $url, array $headers, string $body, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $out = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch); // Handle freigeben (curl_close ab PHP 8.5 deprecated)
        if ($out === false || $errno !== 0) {
            throw new ProviderException($errno === 28 ? 'timeout' : 'unreachable');
        }
        return ['status' => $status, 'body' => (string) $out];
    }

    private function viaStream(string $url, array $headers, string $body, int $timeout): array
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new ProviderException('unreachable');
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $start = microtime(true);
        $out = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }
        if ($out === false || $status === 0) {
            throw new ProviderException((microtime(true) - $start) >= $timeout - 1 ? 'timeout' : 'unreachable');
        }
        return ['status' => $status, 'body' => $out];
    }
}
