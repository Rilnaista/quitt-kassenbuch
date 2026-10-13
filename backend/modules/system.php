<?php

declare(strict_types=1);

use Quitt\Controller\SystemController;
use Quitt\Http\Access;
use Quitt\Http\Registrar;

return static function (Registrar $r): void {
    $r->get('/health', [SystemController::class, 'health'], Access::PUBLIC);
    $r->get('/system/info', [SystemController::class, 'info'], Access::PUBLIC);
    $r->get('/openapi.json', [SystemController::class, 'openapi'], Access::PUBLIC);
};
