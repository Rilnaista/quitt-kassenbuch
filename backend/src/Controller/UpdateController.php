<?php

declare(strict_types=1);

namespace Quitt\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Quitt\Service\UpdateService;

/** GET /update/check, POST /update/apply (Logik: UpdateService). */
final class UpdateController extends Controller
{
    public function check(Request $request, Response $response): Response
    {
        return $this->json($response, $this->svc(UpdateService::class)->check());
    }

    public function apply(Request $request, Response $response): Response
    {
        return $this->json($response, $this->svc(UpdateService::class)->apply());
    }
}
