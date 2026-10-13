<?php

declare(strict_types=1);

namespace Quitt\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quitt\Db\Migrator;

/** Wendet ausstehende Migrationen vor dem Request an (inkl. automatischer Sicherung). */
final class MigrationMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->migrator->migrate();
        return $handler->handle($request);
    }
}
