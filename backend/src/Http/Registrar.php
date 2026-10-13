<?php

declare(strict_types=1);

namespace Quitt\Http;

use Quitt\App\Container;
use Quitt\Http\Middleware\AccessMiddleware;
use Quitt\Service\DownloadService;
use Slim\App;
use Slim\Interfaces\RouteInterface;

/**
 * Wird an jedes Modul (modules/<domaene>.php) uebergeben. Routen werden relativ zu
 * <base>/api/v1 registriert, IMMER mit Zugriffsstufe (Access::*).
 * Das Inventar (routes()) dient dem Abgleich mit openapi.json im Test.
 */
final class Registrar
{
    /** @var list<array{method: string, path: string, access: string, ticket: bool}> */
    private array $routes = [];

    public function __construct(public readonly App $app, public readonly Container $container)
    {
    }

    public function get(string $path, callable|array|string $handler, string $access): RouteInterface
    {
        return $this->map('GET', $path, $handler, $access);
    }

    public function post(string $path, callable|array|string $handler, string $access): RouteInterface
    {
        return $this->map('POST', $path, $handler, $access);
    }

    public function put(string $path, callable|array|string $handler, string $access): RouteInterface
    {
        return $this->map('PUT', $path, $handler, $access);
    }

    public function delete(string $path, callable|array|string $handler, string $access): RouteInterface
    {
        return $this->map('DELETE', $path, $handler, $access);
    }

    /** GET-Route, die zusaetzlich ?ticket= (POST /downloads) akzeptiert. */
    public function download(string $path, callable|array|string $handler, string $access): RouteInterface
    {
        return $this->map('GET', $path, $handler, $access, true);
    }

    /** Download-Ziel fuer POST /downloads registrieren (siehe DownloadService). */
    public function downloadTarget(string $name, string $access, callable $resolver): void
    {
        $this->container->get(DownloadService::class)->registerTarget($name, $access, $resolver);
    }

    public function map(string $method, string $path, callable|array|string $handler, string $access, bool $ticket = false): RouteInterface
    {
        if (!in_array($access, Access::ALL, true)) {
            throw new \InvalidArgumentException('Unbekannte Zugriffsstufe: ' . $access);
        }
        $this->routes[] = ['method' => $method, 'path' => $path, 'access' => $access, 'ticket' => $ticket];
        return $this->app->map([$method], $path, $handler)
            ->add(new AccessMiddleware($this->container, $access, $ticket));
    }

    /** @return list<array{method: string, path: string, access: string, ticket: bool}> */
    public function routes(): array
    {
        return $this->routes;
    }
}
