<?php

declare(strict_types=1);

/*
 * Erstinstallation per Kommandozeile (Entwicklung/Tests).
 *   php bin/install.php "<Gruppenname>" <PIN>
 * Datenordner: ENV QUITT_DATA_DIR oder backend/data. Gibt den Wiederherstellungscode aus.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$appDir = dirname(__DIR__);
require $appDir . '/vendor/autoload.php';

use Quitt\App\Bootstrap;
use Quitt\App\Config;
use Quitt\App\DataDir;
use Quitt\Http\ApiException;
use Quitt\I18n\Lang;
use Quitt\Service\InstallService;

if ($argc < 3) {
    fwrite(STDERR, "Aufruf: php bin/install.php \"<Gruppenname>\" <PIN>\n");
    exit(2);
}

$config = Config::fromEnvironment($appDir);
DataDir::ensure($config->dataDir);
$c = Bootstrap::container($config);

try {
    $code = $c->get(InstallService::class)->install($argv[1], $argv[2]);
} catch (ApiException $e) {
    $lang = $c->get(Lang::class);
    foreach ($e->extra['fields'] ?? [] as $field => $msg) {
        fwrite(STDERR, $field . ': ' . $lang->t((string) $msg) . "\n");
    }
    exit(1);
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "Installiert in {$config->dataDir}\n";
echo "Wiederherstellungscode: {$code}\n";
