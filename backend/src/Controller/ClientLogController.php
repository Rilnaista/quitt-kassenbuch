<?php

declare(strict_types=1);

namespace Quitt\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Quitt\Service\ClientLogService;

final class ClientLogController extends Controller
{
    public function store(Request $request, Response $response): Response
    {
        $this->svc(ClientLogService::class)->accept($this->body($request));
        return $this->noContent($response);
    }
}
