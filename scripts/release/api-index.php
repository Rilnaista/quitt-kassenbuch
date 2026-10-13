<?php

declare(strict_types=1);

/*
 * Quitt API - einziger Einstieg im Installationspaket (<installationsordner>/api/index.php).
 * Programmdateien: ../app, Daten: ../data (oder ENV QUITT_DATA_DIR bzw. app/config.local.php ['data_dir']).
 * Erzeugt von scripts/build-release.sh. Lizenz: AGPL-3.0-or-later
 */

$root = dirname(__DIR__);

if (PHP_VERSION_ID < 80100 || !is_file($root . '/app/vendor/autoload.php')) {
    http_response_code(PHP_VERSION_ID < 80100 ? 500 : 503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo PHP_VERSION_ID < 80100
        ? '{"error":{"code":"internal_error","message":"Quitt braucht PHP 8.1 oder neuer. Bitte die PHP-Version im Kundenmenü des Hosters umstellen."}}'
        : '{"error":{"code":"not_installed","message":"Quitt ist noch nicht eingerichtet."}}';
    return;
}

require $root . '/app/vendor/autoload.php';

\Quitt\App\Bootstrap::run($root . '/app', $root . '/data');
