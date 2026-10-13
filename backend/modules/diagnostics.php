<?php

declare(strict_types=1);

use Quitt\App\Config;
use Quitt\Controller\DiagnosticsController;
use Quitt\Db\Database;
use Quitt\Db\Meta;
use Quitt\Db\Migrator;
use Quitt\Http\Access;
use Quitt\Http\Registrar;
use Quitt\I18n\Lang;
use Quitt\Log\Logger;
use Quitt\Service\BackupService;
use Quitt\Service\DiagnosticsService;
use Quitt\Service\LogReader;
use Quitt\Service\SettingsService;

// REQ-071 Protokoll + Diagnosepaket.
return static function (Registrar $r): void {
    $r->container->set(LogReader::class, fn ($c) => new LogReader($c->get(Logger::class)));
    $r->container->set(DiagnosticsService::class, fn ($c) => new DiagnosticsService(
        $c->get(Config::class), $c->get(Database::class), $c->get(Meta::class), $c->get(Migrator::class),
        $c->get(SettingsService::class), $c->get(LogReader::class), $c->get(Lang::class), $c->get(BackupService::class)
    ));

    $r->get('/logs', [DiagnosticsController::class, 'logs'], Access::PRIVATE);
    $r->download('/diagnostics', [DiagnosticsController::class, 'diagnostics'], Access::PRIVATE);

    $r->downloadTarget('diagnostics', Access::PRIVATE, static fn (array $params): string => 'diagnostics');
};
