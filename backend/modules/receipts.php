<?php

declare(strict_types=1);

use Quitt\Controller\ReceiptController;
use Quitt\Http\Access;
use Quitt\Http\ApiException;
use Quitt\Http\Registrar;
use Quitt\Service\ReceiptService;
use Quitt\Support\Uuid;

return static function (Registrar $r): void {
    $r->put('/receipts/{id}', [ReceiptController::class, 'put'], Access::WRITE);
    $r->download('/receipts/{id}/file', [ReceiptController::class, 'file'], Access::READ);

    // POST /downloads { target: "receipt", params: { id } }
    $r->downloadTarget('receipt', Access::READ, static function (array $params) use ($r): string {
        $id = $params['id'] ?? null;
        if (!Uuid::isV4($id)) {
            throw ApiException::validation(['params.id' => 'validation.uuid']);
        }
        if (!$r->container->get(ReceiptService::class)->exists($id)) {
            throw ApiException::notFound();
        }
        return 'receipts/' . strtolower($id) . '/file';
    });
};
