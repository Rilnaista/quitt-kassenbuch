<?php

declare(strict_types=1);

namespace Quitt\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Json
{
    public const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public static function respond(ResponseInterface $response, mixed $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, self::FLAGS));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * JSON-Objekt aus dem Request-Body. Leerer Body -> [].
     * @return array<string, mixed>
     */
    public static function body(ServerRequestInterface $request): array
    {
        $raw = (string) $request->getBody();
        if (trim($raw) === '') {
            $parsed = $request->getParsedBody();
            return is_array($parsed) ? $parsed : [];
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiException::badRequest('error.invalid_json');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw ApiException::badRequest('error.invalid_json');
        }
        return $data;
    }
}
