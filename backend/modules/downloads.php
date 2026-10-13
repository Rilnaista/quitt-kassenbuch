<?php

declare(strict_types=1);

use Quitt\Controller\DownloadController;
use Quitt\Http\Access;
use Quitt\Http\Registrar;

// Ziele registrieren die Domaenen selbst ($r->downloadTarget(...)), z. B. modules/receipts.php.
return static function (Registrar $r): void {
    $r->post('/downloads', [DownloadController::class, 'create'], Access::OPEN);
};
