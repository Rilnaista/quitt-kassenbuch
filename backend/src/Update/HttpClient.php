<?php

declare(strict_types=1);

namespace Quitt\Update;

/**
 * Minimaler HTTP-GET-Client fuer die Update-Pruefung (GitHub-API) und den Paket-Download.
 * Austauschbar, damit Tests ohne Netz laufen. Folgt Weiterleitungen (GitHub-Assets liegen auf einem CDN).
 */
interface HttpClient
{
    /**
     * @param list<string> $headers Zeilen "Name: Wert"
     * @return array{status: int, body: string}
     * @throws \RuntimeException wenn der Server nicht erreichbar ist
     */
    public function get(string $url, array $headers, int $timeoutSeconds): array;

    /**
     * Laedt $url nach $target (Datei wird angelegt/ueberschrieben).
     * @param list<string> $headers
     * @return int HTTP-Status
     * @throws \RuntimeException wenn der Server nicht erreichbar ist oder nicht geschrieben werden kann
     */
    public function download(string $url, array $headers, string $target, int $timeoutSeconds): int;
}
