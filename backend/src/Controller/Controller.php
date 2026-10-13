<?php

declare(strict_types=1);

namespace Quitt\Controller;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Quitt\Http\AuthContext;
use Quitt\Http\Json;

/**
 * Basis fuer Controller (eine Klasse je Domaene). Slim instanziiert Controller mit
 * new X($container); Logik gehoert in Services (ohne HTTP testbar).
 */
abstract class Controller
{
    public function __construct(protected readonly ContainerInterface $c)
    {
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected function svc(string $class): object
    {
        return $this->c->get($class);
    }

    protected function json(ResponseInterface $response, mixed $data, int $status = 200): ResponseInterface
    {
        return Json::respond($response, $data, $status);
    }

    /** @return array<string, mixed> */
    protected function body(ServerRequestInterface $request): array
    {
        return Json::body($request);
    }

    protected function auth(ServerRequestInterface $request): AuthContext
    {
        $ctx = $request->getAttribute(AuthContext::ATTRIBUTE);
        return $ctx instanceof AuthContext ? $ctx : AuthContext::anonymous();
    }

    protected function noContent(ResponseInterface $response): ResponseInterface
    {
        return $response->withStatus(204);
    }
}
