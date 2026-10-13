<?php

declare(strict_types=1);

use Quitt\Controller\SyncController;
use Quitt\Controller\TrashController;
use Quitt\Http\Access;
use Quitt\Http\Registrar;

return static function (Registrar $r): void {
    $r->get('/sync', [SyncController::class, 'pull'], Access::READ);
    $r->post('/sync', [SyncController::class, 'push'], Access::WRITE);
    $r->delete('/entries/{id}', [TrashController::class, 'deleteEntry'], Access::WRITE);
    $r->post('/trash/empty', [TrashController::class, 'empty'], Access::WRITE);
};
