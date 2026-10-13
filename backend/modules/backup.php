<?php

declare(strict_types=1);

use Quitt\App\Config;
use Quitt\Controller\BackupController;
use Quitt\Db\Database;
use Quitt\Db\Migrator;
use Quitt\Http\Access;
use Quitt\Http\Registrar;
use Quitt\Service\BackupService;
use Quitt\Service\RestoreService;
use Quitt\Log\Logger;

// REQ-062 Sicherung / Wiederherstellen. BackupService selbst wird im Bootstrap registriert.
return static function (Registrar $r): void {
    $r->container->set(RestoreService::class, fn ($c) => new RestoreService(
        $c->get(Config::class), $c->get(Database::class), $c->get(Migrator::class), $c->get(BackupService::class),
        $c->get(Logger::class)->withChannel('restore')
    ));

    $r->download('/backup', [BackupController::class, 'download'], Access::PRIVATE);
    $r->get('/backups', [BackupController::class, 'list'], Access::PRIVATE);
    $r->post('/restore', [BackupController::class, 'restore'], Access::WRITE);
    $r->post('/backups/{name}/restore', [BackupController::class, 'restoreAutomatic'], Access::WRITE);

    $r->downloadTarget('backup', Access::PRIVATE, static fn (array $params): string => 'backup');
};
