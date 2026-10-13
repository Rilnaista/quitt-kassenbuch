<?php

declare(strict_types=1);

use Quitt\Controller\AuthController;
use Quitt\Http\Access;
use Quitt\Http\Registrar;

return static function (Registrar $r): void {
    $r->post('/auth/login', [AuthController::class, 'login'], Access::OPEN);
    $r->get('/auth/status', [AuthController::class, 'status'], Access::OPEN);
    $r->post('/auth/logout', [AuthController::class, 'logout'], Access::PRIVATE);
    $r->post('/auth/pin', [AuthController::class, 'changePin'], Access::WRITE);
    $r->get('/auth/reset', [AuthController::class, 'resetInfo'], Access::OPEN);
    $r->post('/auth/reset', [AuthController::class, 'reset'], Access::OPEN);
};
