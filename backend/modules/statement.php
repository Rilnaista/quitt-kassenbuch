<?php

declare(strict_types=1);

use Quitt\App\Config;
use Quitt\Controller\StatementController;
use Quitt\Db\Database;
use Quitt\Http\Access;
use Quitt\Http\Registrar;
use Quitt\I18n\Lang;
use Quitt\Service\SettingsService;
use Quitt\Service\StatementPaginator;
use Quitt\Service\StatementPdf;
use Quitt\Service\StatementService;
use Quitt\Support\Clock;

return static function (Registrar $r): void {
    $r->container->set(StatementService::class, fn ($c) => new StatementService(
        $c->get(Database::class), $c->get(SettingsService::class), $c->get(Lang::class), $c->get(Clock::class),
        new StatementPaginator(), new StatementPdf($c->get(Config::class))
    ));

    $r->download('/statement.pdf', [StatementController::class, 'pdf'], Access::READ);

    // POST /downloads { target: "statement", params: { from, to } }
    $r->downloadTarget('statement', Access::READ, static function (array $params) use ($r): string {
        $from = $params['from'] ?? null;
        $to = $params['to'] ?? null;
        $r->container->get(StatementService::class)->validateRange($from, $to);
        return 'statement.pdf?from=' . $from . '&to=' . $to;
    });
};
