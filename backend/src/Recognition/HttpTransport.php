<?php

declare(strict_types=1);

namespace Quitt\Recognition;

/** Minimaler HTTP-Client (nur POST) – austauschbar, damit Tests ohne Netz laufen. */
interface HttpTransport
{
    /**
     * @param list<string> $headers Zeilen "Name: Wert"
     * @return array{status: int, body: string}
     * @throws ProviderException kind "timeout" oder "unreachable"
     */
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): array;
}
