<?php

declare(strict_types=1);

namespace Quitt\Http;

/**
 * Zugriffsstufen je Route (siehe api-contract "Auth"). In openapi.json als x-quitt-access dokumentiert.
 */
final class Access
{
    /** ohne Auth, auch vor der Installation (health, system/info, openapi, logs/client) */
    public const PUBLIC = 'public';
    /** ohne Auth, aber nur nach Installation (login, status, reset) */
    public const OPEN = 'open';
    /** lesend: Token ODER read_without_pin (nur GET/HEAD) */
    public const READ = 'read';
    /** schreibend: Token; ohne Token 403 pin_required (bei read_without_pin) sonst 401 */
    public const WRITE = 'write';
    /** immer Token (Logs, Diagnose, Sicherung, Logout) */
    public const PRIVATE = 'private';

    public const ALL = [self::PUBLIC, self::OPEN, self::READ, self::WRITE, self::PRIVATE];
}
