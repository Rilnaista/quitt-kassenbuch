<?php

declare(strict_types=1);

namespace Quitt\Http;

/** Request-Attribut 'auth': wie ist der Aufrufer berechtigt? */
final class AuthContext
{
    public const ATTRIBUTE = 'auth';

    private function __construct(
        public readonly bool $authenticated,
        public readonly string $via,
        public readonly ?string $tokenHash = null,
    ) {
    }

    public static function anonymous(): self
    {
        return new self(false, 'anonymous');
    }

    public static function token(string $hash): self
    {
        return new self(true, 'token', $hash);
    }

    public static function ticket(): self
    {
        return new self(true, 'ticket');
    }
}
