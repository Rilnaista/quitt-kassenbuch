<?php

declare(strict_types=1);

namespace Quitt\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Quitt\Http\ApiException;
use Quitt\Service\TrashService;
use Quitt\Support\Uuid;

final class TrashController extends Controller
{
    /** @param array{id: string} $args */
    public function deleteEntry(Request $request, Response $response, array $args): Response
    {
        if (!Uuid::isV4($args['id'])) {
            throw ApiException::notFound();
        }
        $this->svc(TrashService::class)->deleteEntry($args['id']);
        return $this->noContent($response);
    }

    public function empty(Request $request, Response $response): Response
    {
        return $this->json($response, ['purged' => $this->svc(TrashService::class)->emptyTrash()]);
    }
}
