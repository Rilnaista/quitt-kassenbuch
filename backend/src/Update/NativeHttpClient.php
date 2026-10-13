<?php

declare(strict_types=1);

namespace Quitt\Update;

/** HTTP per curl, Fallback auf Streams (allow_url_fopen), wenn die curl-Extension fehlt. */
final class NativeHttpClient implements HttpClient
{
    private const MAX_REDIRECTS = 5;

    public function get(string $url, array $headers, int $timeoutSeconds): array
    {
        if (function_exists('curl_init')) {
            $ch = $this->curl($url, $headers, $timeoutSeconds);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $out = curl_exec($ch);
            $errno = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            unset($ch);
            if ($out === false || $errno !== 0) {
                throw new \RuntimeException('HTTP-Fehler (curl ' . $errno . ')');
            }
            return ['status' => $status, 'body' => (string) $out];
        }
        $in = $this->stream($url, $headers, $timeoutSeconds, $meta);
        $body = stream_get_contents($in);
        fclose($in);
        return ['status' => self::statusFrom($meta), 'body' => (string) $body];
    }

    public function download(string $url, array $headers, string $target, int $timeoutSeconds): int
    {
        $out = @fopen($target, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Zieldatei nicht schreibbar');
        }
        try {
            if (function_exists('curl_init')) {
                $ch = $this->curl($url, $headers, $timeoutSeconds);
                curl_setopt($ch, CURLOPT_FILE, $out);
                $ok = curl_exec($ch);
                $errno = curl_errno($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                unset($ch);
                if ($ok === false || $errno !== 0) {
                    throw new \RuntimeException('Download fehlgeschlagen (curl ' . $errno . ')');
                }
                return $status;
            }
            $in = $this->stream($url, $headers, $timeoutSeconds, $meta);
            stream_copy_to_stream($in, $out);
            fclose($in);
            return self::statusFrom($meta);
        } finally {
            fclose($out);
        }
    }

    /** @return \CurlHandle */
    private function curl(string $url, array $headers, int $timeout): mixed
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        return $ch;
    }

    /** @return resource */
    private function stream(string $url, array $headers, int $timeout, ?array &$meta)
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new \RuntimeException('Weder curl noch allow_url_fopen verfuegbar');
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => self::MAX_REDIRECTS,
        ]]);
        $in = @fopen($url, 'rb', false, $ctx);
        if ($in === false) {
            throw new \RuntimeException('Server nicht erreichbar');
        }
        $meta = stream_get_meta_data($in)['wrapper_data'] ?? [];
        return $in;
    }

    /** Letzte Statuszeile (nach Weiterleitungen) */
    private static function statusFrom(?array $lines): int
    {
        $status = 0;
        foreach ($lines ?? [] as $line) {
            if (is_string($line) && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }
        return $status;
    }
}
