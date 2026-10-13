<?php

declare(strict_types=1);

namespace Quitt\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Quitt\Http\Access;
use Quitt\Http\ApiException;
use Quitt\Service\DownloadService;
use Quitt\Service\SettingsService;

final class DownloadController extends Controller
{
    /** POST /downloads { target, params } -> { url } ; Berechtigung je Ziel (READ/PRIVATE) */
    public function create(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        $target = $body['target'] ?? null;
        $params = $body['params'] ?? [];
        $downloads = $this->svc(DownloadService::class);
        if (!is_string($target) || !$downloads->hasTarget($target)) {
            throw ApiException::validation(['target' => 'validation.invalid_choice']);
        }
        if (!is_array($params)) {
            throw ApiException::validation(['params' => 'validation.object']);
        }
        if (!$this->auth($request)->authenticated) {
            $readable = $downloads->targetAccess($target) === Access::READ
                && (bool) $this->svc(SettingsService::class)->get('read_without_pin');
            if (!$readable) {
                throw ApiException::unauthorized();
            }
        }
        return $this->json($response, ['url' => $downloads->create($target, $params)]);
    }
}
