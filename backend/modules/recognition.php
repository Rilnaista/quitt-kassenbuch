<?php

declare(strict_types=1);

use Quitt\App\Container;
use Quitt\Controller\RecognitionController;
use Quitt\Http\Access;
use Quitt\Http\Registrar;
use Quitt\I18n\Lang;
use Quitt\Log\Logger;
use Quitt\Recognition\CurlTransport;
use Quitt\Recognition\HttpTransport;
use Quitt\Recognition\RecognitionService;
use Quitt\Service\AiProviders;
use Quitt\Service\ReceiptService;
use Quitt\Service\SettingsService;

// KI-Belegerkennung (REQ-010/011). Tests ersetzen den HttpTransport per $container->instance().
return static function (Registrar $r): void {
    $r->container->set(HttpTransport::class, fn () => new CurlTransport());
    $r->container->set(RecognitionService::class, fn (Container $c) => new RecognitionService(
        $c->get(SettingsService::class),
        $c->get(AiProviders::class),
        $c->get(ReceiptService::class),
        $c->get(HttpTransport::class),
        $c->get(Lang::class),
        $c->get(Logger::class)->withChannel('recognition'),
    ));
    $r->post('/receipts/{id}/recognize', [RecognitionController::class, 'recognize'], Access::WRITE);
    $r->post('/settings/recognition/test', [RecognitionController::class, 'test'], Access::WRITE);
};
