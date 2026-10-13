<?php

declare(strict_types=1);

use Quitt\Controller\ClientLogController;
use Quitt\Http\Access;
use Quitt\Http\Registrar;

return static function (Registrar $r): void {
    $r->post('/logs/client', [ClientLogController::class, 'store'], Access::PUBLIC);
};
