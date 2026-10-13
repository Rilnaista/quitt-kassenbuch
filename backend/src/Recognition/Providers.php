<?php

declare(strict_types=1);

namespace Quitt\Recognition;

/**
 * Anfrage-Aufbau und Antwort-Extraktion je Anbieter (Chat/Vision-API, Bild als base64).
 * JSON-Antwort wird dort erzwungen, wo die API es kann (Mistral/OpenAI json_object,
 * Gemini responseMimeType, Ollama format=json); Anthropic ueber Prompt + toleranten Parser.
 */
final class Providers
{
    public const API_VERSION_ANTHROPIC = '2023-06-01';

    /**
     * @param array{mime: string, b64: string}|null $image null = reine Textanfrage (Verbindungstest)
     * @return array{url: string, headers: list<string>, body: string}
     */
    public static function buildRequest(string $provider, string $model, ?string $key, ?string $baseUrl, string $prompt, ?array $image, bool $probe): array
    {
        $json = ['Content-Type: application/json', 'Accept: application/json'];
        $dataUrl = $image !== null ? 'data:' . $image['mime'] . ';base64,' . $image['b64'] : null;
        switch ($provider) {
            case 'mistral':
                $content = [['type' => 'text', 'text' => $prompt]];
                if ($dataUrl !== null) {
                    $content[] = ['type' => 'image_url', 'image_url' => $dataUrl];
                }
                $body = ['model' => $model, 'temperature' => 0, 'messages' => [['role' => 'user', 'content' => $content]]];
                if ($probe) {
                    $body['max_tokens'] = 8;
                } else {
                    $body['response_format'] = ['type' => 'json_object'];
                }
                return self::req('https://api.mistral.ai/v1/chat/completions', [...$json, 'Authorization: Bearer ' . $key], $body);
            case 'openai':
                $content = [['type' => 'text', 'text' => $prompt]];
                if ($dataUrl !== null) {
                    $content[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUrl, 'detail' => 'high']];
                }
                $body = ['model' => $model, 'messages' => [['role' => 'user', 'content' => $content]]];
                if ($probe) {
                    $body['max_completion_tokens'] = 16;
                } else {
                    $body['response_format'] = ['type' => 'json_object'];
                }
                return self::req('https://api.openai.com/v1/chat/completions', [...$json, 'Authorization: Bearer ' . $key], $body);
            case 'anthropic':
                $content = [];
                if ($image !== null) {
                    $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image['mime'], 'data' => $image['b64']]];
                }
                $content[] = ['type' => 'text', 'text' => $prompt];
                $body = ['model' => $model, 'max_tokens' => $probe ? 16 : 4096, 'messages' => [['role' => 'user', 'content' => $content]]];
                return self::req('https://api.anthropic.com/v1/messages', [...$json, 'x-api-key: ' . $key, 'anthropic-version: ' . self::API_VERSION_ANTHROPIC], $body);
            case 'gemini':
                $parts = [['text' => $prompt]];
                if ($image !== null) {
                    $parts[] = ['inline_data' => ['mime_type' => $image['mime'], 'data' => $image['b64']]];
                }
                $gen = ['temperature' => 0];
                if ($probe) {
                    $gen['maxOutputTokens'] = 64;
                } else {
                    $gen['responseMimeType'] = 'application/json';
                }
                $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
                return self::req($url, [...$json, 'x-goog-api-key: ' . $key], ['contents' => [['role' => 'user', 'parts' => $parts]], 'generationConfig' => $gen]);
            case 'ollama':
                $msg = ['role' => 'user', 'content' => $prompt];
                if ($image !== null) {
                    $msg['images'] = [$image['b64']];
                }
                $body = ['model' => $model, 'stream' => false, 'messages' => [$msg], 'options' => ['temperature' => 0]];
                if ($probe) {
                    $body['options']['num_predict'] = 8;
                } else {
                    $body['format'] = 'json';
                }
                $headers = $json;
                if ($key !== null && $key !== '') {
                    $headers[] = 'Authorization: Bearer ' . $key;
                }
                return self::req(rtrim((string) $baseUrl, '/') . '/api/chat', $headers, $body);
        }
        throw new \InvalidArgumentException('Unbekannter Anbieter');
    }

    /** Antworttext aus der Anbieter-Antwort; null wenn die Struktur nicht passt. */
    public static function extractText(string $provider, mixed $json): ?string
    {
        if (!is_array($json)) {
            return null;
        }
        switch ($provider) {
            case 'mistral':
            case 'openai':
                $c = $json['choices'][0]['message']['content'] ?? null;
                if (is_array($c)) { // Mistral kann Content-Chunks liefern
                    $c = implode('', array_map(static fn ($p) => is_array($p) ? (string) ($p['text'] ?? '') : (string) $p, $c));
                }
                return is_string($c) ? $c : null;
            case 'anthropic':
                $out = '';
                foreach ((array) ($json['content'] ?? []) as $p) {
                    if (is_array($p) && ($p['type'] ?? '') === 'text') {
                        $out .= (string) ($p['text'] ?? '');
                    }
                }
                return $out !== '' ? $out : null;
            case 'gemini':
                $out = '';
                foreach ((array) ($json['candidates'][0]['content']['parts'] ?? []) as $p) {
                    if (is_array($p) && isset($p['text']) && empty($p['thought'])) {
                        $out .= (string) $p['text'];
                    }
                }
                return $out !== '' ? $out : null;
            case 'ollama':
                $c = $json['message']['content'] ?? null;
                return is_string($c) ? $c : null;
        }
        return null;
    }

    /** @return array{url: string, headers: list<string>, body: string} */
    private static function req(string $url, array $headers, array $body): array
    {
        return ['url' => $url, 'headers' => $headers, 'body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
    }
}
