<?php

declare(strict_types=1);

namespace Quitt\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Quitt\Http\ApiException;
use Quitt\Recognition\RecognitionService;

/** POST /receipts/{id}/recognize und POST /settings/recognition/test (Logik: RecognitionService). */
final class RecognitionController extends Controller
{
    public function recognize(Request $request, Response $response, array $args): Response
    {
        $id = strtolower((string) ($args['id'] ?? ''));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            throw ApiException::notFound();
        }
        return $this->json($response, $this->svc(RecognitionService::class)->recognize($id));
    }

    public function test(Request $request, Response $response): Response
    {
        return $this->json($response, $this->svc(RecognitionService::class)->test($this->body($request)));
    }
}
