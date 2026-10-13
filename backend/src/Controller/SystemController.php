<?php

declare(strict_types=1);

namespace Quitt\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Quitt\App\Config;
use Quitt\Http\ApiException;
use Quitt\Service\AuthService;
use Quitt\Service\SettingsService;
use Quitt\Support\Environment;

final class SystemController extends Controller
{
    public function health(Request $request, Response $response): Response
    {
        return $this->json($response, ['ok' => true, 'version' => $this->svc(Config::class)->version()]);
    }

    public function info(Request $request, Response $response): Response
    {
        $installed = $this->svc(AuthService::class)->isInstalled();
        $settings = $installed
            ? $this->svc(SettingsService::class)->publicView()
            : $this->svc(SettingsService::class)->defaults();
        return $this->json($response, [
            'version' => $this->svc(Config::class)->version(),
            'installed' => $installed,
            'https' => Environment::isHttps($request),
            'docker' => Environment::isDocker(),
            'base_path' => $this->c->get('base_path') . '/',
            'read_without_pin' => (bool) $settings['read_without_pin'],
            'group_name' => (string) $settings['group_name'],
        ]);
    }

    /**
     * Liefert openapi.json unter <base>/api/v1/openapi.json aus.
     * Entwicklung: <app>/public/api/openapi.json; Release-Paket: <installationsordner>/api/openapi.json.
     */
    public function openapi(Request $request, Response $response): Response
    {
        $appDir = $this->svc(Config::class)->appDir;
        $file = $appDir . '/public/api/openapi.json';
        if (!is_file($file)) {
            $file = dirname($appDir) . '/api/openapi.json';
        }
        if (!is_file($file)) {
            throw ApiException::notFound();
        }
        $response->getBody()->write((string) file_get_contents($file));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
