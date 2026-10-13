<?php

declare(strict_types=1);

namespace Quitt\Http;

/**
 * Fachlicher API-Fehler -> { "error": { "code", "message", ...extra } }.
 * message wird im ErrorHandler aus lang ("error.<code>" bzw. $messageKey) uebersetzt.
 */
class ApiException extends \RuntimeException
{
    /**
     * @param array<string, mixed>  $extra   zusaetzliche Felder im error-Objekt (fields, retry_after)
     * @param array<string, string> $headers zusaetzliche Response-Header
     * @param array<string, string|int> $params Platzhalter fuer die Meldung
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly ?string $messageKey = null,
        public readonly array $extra = [],
        public readonly array $headers = [],
        public readonly array $params = [],
    ) {
        parent::__construct($errorCode, $status);
    }

    /** @param array<string, mixed> $fields Feld => Meldung (bereits uebersetzt) */
    public static function validation(array $fields): self
    {
        return new self(422, 'validation_failed', null, ['fields' => $fields]);
    }

    public static function unauthorized(): self
    {
        return new self(401, 'unauthorized');
    }

    public static function pinRequired(): self
    {
        return new self(403, 'pin_required');
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found');
    }

    public static function notInstalled(): self
    {
        return new self(503, 'not_installed');
    }

    public static function rateLimited(int $retryAfter): self
    {
        $retryAfter = max(1, $retryAfter);
        return new self(429, 'rate_limited', null, ['retry_after' => $retryAfter], ['Retry-After' => (string) $retryAfter], ['seconds' => $retryAfter]);
    }

    public static function badRequest(string $messageKey = 'error.bad_request'): self
    {
        return new self(400, 'bad_request', $messageKey);
    }
}
