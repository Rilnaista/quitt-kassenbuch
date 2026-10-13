<?php

declare(strict_types=1);

/*
 * Quitt API - einziger Einstieg. Alle Anfragen unter <base>/api/v1/* landen hier (.htaccess).
 * Lizenz: AGPL-3.0-or-later
 */

$appDir = dirname(__DIR__, 2);
require $appDir . '/vendor/autoload.php';

\Quitt\App\Bootstrap::run($appDir);
