<?php

declare(strict_types=1);

namespace Quitt\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Quitt\Http\ApiException;
use Quitt\Service\SyncService;

final class SyncController extends Controller
{
    public function pull(Request $request, Response $response): Response
    {
        $since = $request->getQueryParams()['since'] ?? '0';
        if (!is_string($since) || !preg_match('/^\d{1,18}$/', $since)) {
            throw ApiException::validation(['since' => 'validation.since']);
        }
        return $this->json($response, $this->svc(SyncService::class)->changesSince((int) $since));
    }

    public function push(Request $request, Response $response): Response
    {
        return $this->json($response, $this->svc(SyncService::class)->apply($this->body($request)));
    }
}
