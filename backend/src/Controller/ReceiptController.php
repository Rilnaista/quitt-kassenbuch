<?php

declare(strict_types=1);

namespace Quitt\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Quitt\Http\ApiException;
use Quitt\Http\Multipart;
use Quitt\Service\ReceiptService;
use Quitt\Support\Uuid;
use Slim\Psr7\Stream;

final class ReceiptController extends Controller
{
    /** PUT /receipts/{id} multipart "file" */
    public function put(Request $request, Response $response, array $args): Response
    {
        if (!Uuid::isV4($args['id'])) {
            throw ApiException::validation(['id' => 'validation.uuid']);
        }
        $receipts = $this->svc(ReceiptService::class);
        $file = Multipart::file($request, 'file', $receipts->maxBytes());
        if ($file === null) {
            throw ApiException::validation(['file' => 'validation.required']);
        }
        $result = $receipts->store($args['id'], $file['bytes']);
        return $this->json($response, $result['receipt'], $result['created'] ? 201 : 200);
    }

    /** GET /receipts/{id}/file (Token, Lesen ohne PIN oder Ticket) */
    public function file(Request $request, Response $response, array $args): Response
    {
        $receipts = $this->svc(ReceiptService::class);
        $receipt = Uuid::isV4($args['id']) ? $receipts->find($args['id']) : null;
        $path = $receipt === null ? null : $receipts->filePath($receipt['id']);
        if ($receipt === null || !is_file($path)) {
            throw ApiException::notFound();
        }
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$receipt['mime']];
        $fh = fopen($path, 'rb');
        return $response
            ->withBody(new Stream($fh))
            ->withHeader('Content-Type', $receipt['mime'])
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Content-Disposition', 'inline; filename="beleg-' . substr($receipt['id'], 0, 8) . '.' . $ext . '"');
    }
}
