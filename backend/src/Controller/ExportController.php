<?php

declare(strict_types=1);

namespace Quitt\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Quitt\Service\ExportService;

final class ExportController extends Controller
{
    /** GET /export.xlsx?from&to (Token, Lesen ohne PIN oder Ticket) */
    public function xlsx(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $from = $q['from'] ?? null;
        $to = $q['to'] ?? null;
        $svc = $this->svc(ExportService::class);
        $bytes = $svc->render($from, $to);
        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', ExportService::MIME)
            ->withHeader('Content-Length', (string) strlen($bytes))
            ->withHeader('Content-Disposition', 'attachment; filename="' . ExportService::filename($from, $to) . '"');
    }
}
