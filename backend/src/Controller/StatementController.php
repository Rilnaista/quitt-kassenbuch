<?php

declare(strict_types=1);

namespace Quitt\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Quitt\Service\StatementService;

final class StatementController extends Controller
{
    /** GET /statement.pdf?from&to (Token, Lesen ohne PIN oder Ticket) */
    public function pdf(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $from = $q['from'] ?? null;
        $to = $q['to'] ?? null;
        $svc = $this->svc(StatementService::class);
        $svc->validateRange($from, $to);
        $bytes = $svc->render($from, $to);
        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Length', (string) strlen($bytes))
            ->withHeader('Content-Disposition', 'inline; filename="' . StatementService::filename($from, $to) . '"');
    }
}
