<?php

declare(strict_types=1);

use Quitt\App\Config;
use Quitt\App\Container;
use Quitt\Controller\UpdateController;
use Quitt\Db\Database;
use Quitt\Db\Migrator;
use Quitt\Http\Access;
use Quitt\Http\Registrar;
use Quitt\Log\Logger;
use Quitt\Service\BackupService;
use Quitt\Service\UpdateService;
use Quitt\Support\Clock;
use Quitt\Support\Environment;
use Quitt\Update\HttpClient;
use Quitt\Update\NativeHttpClient;

// REQ-061 In-App-Update. Tests ersetzen den HttpClient per $container->instance().
// Beide Endpunkte nur angemeldet (WRITE: ohne Token 401 bzw. 403 pin_required bei "Lesen ohne PIN").
return static function (Registrar $r): void {
    $r->container->set(HttpClient::class, fn () => new NativeHttpClient());
    $r->container->set(UpdateService::class, fn (Container $c) => new UpdateService(
        $c->get(Config::class),
        $c->get(BackupService::class),
        $c->get(Migrator::class),
        $c->get(Database::class),
        $c->get(HttpClient::class),
        $c->get(Clock::class),
        $c->get(Logger::class)->withChannel('update'),
        Environment::isDocker(),
    ));
    $r->get('/update/check', [UpdateController::class, 'check'], Access::WRITE);
    $r->post('/update/apply', [UpdateController::class, 'apply'], Access::WRITE);
};
