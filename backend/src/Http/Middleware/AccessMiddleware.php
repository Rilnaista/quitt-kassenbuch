<?php

declare(strict_types=1);

namespace Quitt\Http\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quitt\Http\Access;
use Quitt\Http\ApiException;
use Quitt\Http\AuthContext;
use Quitt\Service\AuthService;
use Quitt\Service\DownloadService;
use Quitt\Service\SettingsService;
use Slim\Routing\RouteContext;

/**
 * Route-Middleware: Installationspruefung (503 not_installed), Token, Download-Ticket,
 * Lesen ohne PIN. Setzt das Request-Attribut AuthContext::ATTRIBUTE.
 */
final class AccessMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ContainerInterface $c,
        private readonly string $access,
        private readonly bool $acceptsTicket = false,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->access === Access::PUBLIC) {
            return $handler->handle($request->withAttribute(AuthContext::ATTRIBUTE, AuthContext::anonymous()));
        }
        /** @var AuthService $auth */
        $auth = $this->c->get(AuthService::class);
        if (!$auth->isInstalled()) {
            throw ApiException::notInstalled();
        }

        $ctx = AuthContext::anonymous();
        $token = self::bearer($request);
        if ($token !== null) {
            $hash = $auth->validateToken($token);
            if ($hash !== null) {
                $ctx = AuthContext::token($hash);
            } elseif ($this->access !== Access::OPEN) {
                throw ApiException::unauthorized();
            }
        }
        $ticket = $request->getQueryParams()['ticket'] ?? null;
        if (!$ctx->authenticated && $this->acceptsTicket && is_string($ticket)) {
            /** @var DownloadService $downloads */
            $downloads = $this->c->get(DownloadService::class);
            if (!$downloads->consume($ticket, self::relativePath($request), $request->getQueryParams())) {
                throw ApiException::unauthorized();
            }
            $ctx = AuthContext::ticket();
        }

        if (!$ctx->authenticated) {
            switch ($this->access) {
                case Access::READ:
                    $isRead = in_array($request->getMethod(), ['GET', 'HEAD'], true);
                    if (!$isRead || !$this->readWithoutPin()) {
                        throw ApiException::unauthorized();
                    }
                    break;
                case Access::WRITE:
                    throw $this->readWithoutPin() ? ApiException::pinRequired() : ApiException::unauthorized();
                case Access::PRIVATE:
                    throw ApiException::unauthorized();
            }
        }
        return $handler->handle($request->withAttribute(AuthContext::ATTRIBUTE, $ctx));
    }

    public static function bearer(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header === '') {
            return null;
        }
        return preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) ? $m[1] : '';
    }

    /** Pfad relativ zu <base>/api/v1, ohne fuehrenden Slash. */
    public static function relativePath(ServerRequestInterface $request): string
    {
        $path = $request->getUri()->getPath();
        $base = RouteContext::fromRequest($request)->getBasePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        return ltrim($path, '/');
    }

    private function readWithoutPin(): bool
    {
        return (bool) $this->c->get(SettingsService::class)->get('read_without_pin');
    }
}
