<?php

declare(strict_types=1);

namespace Quitt\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Quitt\Service\AiProviders;
use Quitt\Service\SettingsService;

final class SettingsController extends Controller
{
    public function get(Request $request, Response $response): Response
    {
        return $this->json($response, $this->svc(SettingsService::class)->publicView());
    }

    public function put(Request $request, Response $response): Response
    {
        return $this->json($response, $this->svc(SettingsService::class)->update($this->body($request)));
    }

    public function providers(Request $request, Response $response): Response
    {
        return $this->json($response, $this->svc(AiProviders::class)->all());
    }
}
