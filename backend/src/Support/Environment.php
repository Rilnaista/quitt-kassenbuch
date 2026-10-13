<?php

declare(strict_types=1);

namespace Quitt\Support;

use Psr\Http\Message\ServerRequestInterface;

/** Laufzeitumgebung: HTTPS-, Docker- und Basis-Pfad-Erkennung (nichts fest verdrahtet). */
final class Environment
{
    public static function isHttps(ServerRequestInterface $request): bool
    {
        if (strtolower($request->getUri()->getScheme()) === 'https') {
            return true;
        }
        $server = $request->getServerParams();
        if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
            return true;
        }
        // Reverse-Proxy (z. B. Synology, Hoster-SSL-Proxy)
        $proto = strtolower(trim(explode(',', $request->getHeaderLine('X-Forwarded-Proto'))[0] ?? ''));
        return $proto === 'https';
    }

    public static function isDocker(): bool
    {
        return getenv('QUITT_DOCKER') === '1' || is_file('/.dockerenv');
    }

    /**
     * Installationspfad ("" = Domain-Wurzel, sonst z. B. "/kasse") aus SCRIPT_NAME,
     * der auf .../api/index.php endet.
     */
    public static function basePath(array $server): string
    {
        $script = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? ''));
        $suffix = '/api/index.php';
        if ($script !== '' && str_ends_with($script, $suffix)) {
            return rtrim(substr($script, 0, -strlen($suffix)), '/');
        }
        return '';
    }
}
