<?php

declare(strict_types=1);

use Quitt\Controller\SettingsController;
use Quitt\Http\Access;
use Quitt\Http\Registrar;

return static function (Registrar $r): void {
    $r->get('/settings', [SettingsController::class, 'get'], Access::READ);
    $r->put('/settings', [SettingsController::class, 'put'], Access::WRITE);
    $r->get('/settings/ai-providers', [SettingsController::class, 'providers'], Access::READ);
};
